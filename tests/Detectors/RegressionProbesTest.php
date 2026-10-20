<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Detectors;

use Malikad\QueueGuard\Core\Analyzer;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Fixers\AddAfterCommitFixer;
use Malikad\QueueGuard\Fixers\AddFinallyStubFixer;
use Malikad\QueueGuard\Fixers\AddSerializesModelsFixer;
use PHPUnit\Framework\TestCase;

final class RegressionProbesTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer;
    }

    public function test_issue_6_resolved_alias_and_model_false_positives(): void
    {
        // 1. Alias: use Http as Web; Web::post() must be detected!
        $aliasCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http as Web;

class WebService {
    public function doWork(): void {
        DB::transaction(function () {
            Web::post('https://api.example.com');
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($aliasCode, 'app/Services/WebService.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(1, $qg001);

        // 2. Eloquent models named Charge, Transfer, Refund, Payout must NOT be flagged as Stripe calls!
        $modelCode = <<<'PHP'
<?php
namespace App\Services;
use App\Models\Charge;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class OrderService {
    public function updateRecords(): void {
        DB::transaction(function () {
            Charge::find(1);
            Transfer::create(['amount' => 100]);
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($modelCode, 'app/Services/OrderService.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(0, $qg001);
    }

    public function test_issue_7_is_queued_class_does_not_over_match(): void
    {
        // make:event class with Dispatchable is NOT queued
        $eventCode = <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPlaced {
    use Dispatchable;
    public function __construct(public int $orderId) {}
}
PHP;
        $findings = $this->analyzer->analyseCode($eventCode, 'app/Events/OrderPlaced.php');
        $qg004 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG004);
        self::assertCount(0, $qg004);

        // Non-queued Mailable is NOT queued
        $mailableCode = <<<'PHP'
<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use App\Models\User;

class InvoiceMail extends Mailable {
    public function __construct(public User $user) {}
}
PHP;
        $findings = $this->analyzer->analyseCode($mailableCode, 'app/Mail/InvoiceMail.php');
        $qg003 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG003);
        $qg004 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG004);
        self::assertCount(0, $qg003);
        self::assertCount(0, $qg004);
    }

    public function test_issue_8_no_variable_guessing_and_injected_services_matched(): void
    {
        // $client->delete() on a model must NOT trigger Http call
        $clientCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

class ClientManager {
    public function removeClient($client): void {
        DB::transaction(function () use ($client) {
            $client->delete();
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($clientCode, 'app/Services/ClientManager.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(0, $qg001);

        // Injected gateway on $this->gw->capture() matched via externalCallClasses
        $gwCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

class PaymentService {
    public function __construct(private PaymentGateway $gw) {}

    public function process(): void {
        DB::transaction(function () {
            $this->gw->capture(['id' => 123]);
        });
    }
}
PHP;
        $config = new Config(externalCallClasses: ['App\Services\PaymentGateway']);
        $findings = $this->analyzer->analyseCode($gwCode, 'app/Services/PaymentService.php', $config);
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(1, $qg001);
    }

    public function test_issue_9_transaction_scope_bounds_and_no_duplicates(): void
    {
        // Statements after commit() in try/catch are outside the transaction scope
        $code = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncService {
    public function sync(): void {
        DB::beginTransaction();
        try {
            DB::table('orders')->update(['status' => 'synced']);
            DB::commit();
            // Call AFTER commit is outside transaction!
            Http::post('https://api.example.com/done');
        } catch (\Throwable $e) {
            DB::rollBack();
            // Call in catch after rollback is outside transaction!
            Http::post('https://api.example.com/failed');
        }
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($code, 'app/Services/SyncService.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(0, $qg001);
    }

    public function test_issue_10_queue_safe_scope_exemption_and_typo_prevention(): void
    {
        // 1. Docblock on DB::transaction(...) exempts the entire body
        $exemptCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SafeService {
    public function doWork(): void {
        /** @queue-safe QG001 */
        DB::transaction(function () {
            Http::post('https://api.example.com');
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($exemptCode, 'app/Services/SafeService.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(0, $qg001);

        // 2. Prose like @queue-safe because ... does NOT exempt wildcard
        $invalidCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class NotSafeService {
    public function doWork(): void {
        /** @queue-safe because this is legacy and we know */
        DB::transaction(function () {
            Http::post('https://api.example.com');
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($invalidCode, 'app/Services/NotSafeService.php');
        $qg001 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001);
        self::assertCount(1, $qg001);
    }

    public function test_issue_11_sync_dispatches_not_flagged_and_after_commit_respected(): void
    {
        // dispatchSync, notifyNow, Mail::send are synchronous and NOT QG007
        $syncCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Jobs\RunSyncJob;

class SyncRunner {
    public function run($user): void {
        DB::transaction(function () use ($user) {
            RunSyncJob::dispatchSync();
            $user->notifyNow(new \App\Notifications\WelcomeNotification);
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($syncCode, 'app/Services/SyncRunner.php');
        $qg007 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG007);
        self::assertCount(0, $qg007);

        // Job implementing ShouldQueueAfterCommit or with $afterCommit = true
        $afterCommitJobCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class AutoCommitJob implements ShouldQueueAfterCommit {
    public function handle(): void {}
}

class DispatchedService {
    public function run(): void {
        DB::transaction(function () {
            AutoCommitJob::dispatch();
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($afterCommitJobCode, 'app/Services/DispatchedService.php');
        $qg007 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG007);
        self::assertCount(0, $qg007);
    }

    public function test_issue_12_financial_guards_and_stripe_detection(): void
    {
        // 1. Status check placed AFTER charge does NOT protect the charge
        $afterCheckCode = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stripe\Charge;

class ChargeJob implements ShouldQueue {
    public int $tries = 3;
    public function handle($order): void {
        Charge::create(['amount' => 5000]);
        // Status check placed AFTER charge!
        if ($order->status === 'paid') {
            return;
        }
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($afterCheckCode, 'app/Jobs/ChargeJob.php');
        $qg002 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG002);
        self::assertCount(1, $qg002);

        // 2. attempts() only inside a log line does NOT protect the charge
        $logAttemptCode = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;

class LogJob implements ShouldQueue {
    public int $tries = 3;
    public function handle(): void {
        Log::info('Current attempt: ' . $this->attempts());
        Charge::create(['amount' => 5000]);
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($logAttemptCode, 'app/Jobs/LogJob.php');
        $qg002 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG002);
        self::assertCount(1, $qg002);

        // 3. Idempotency key built in helper method is recognized
        $helperKeyJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stripe\Charge;

class HelperKeyJob implements ShouldQueue {
    public int $tries = 3;
    public function handle(): void {
        Charge::create($this->buildChargePayload());
    }

    private function buildChargePayload(): array {
        return [
            'amount' => 5000,
            'idempotency_key' => 'order_123',
        ];
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($helperKeyJob, 'app/Jobs/HelperKeyJob.php');
        $qg002 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG002);
        self::assertCount(0, $qg002);

        // 4. Modern Stripe SDK $stripe->paymentIntents->create() is detected
        $modernStripeJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class ModernStripeJob implements ShouldQueue {
    public int $tries = 3;
    public function handle($stripe): void {
        $stripe->paymentIntents->create(['amount' => 1000]);
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($modernStripeJob, 'app/Jobs/ModernStripeJob.php');
        $qg002 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG002);
        self::assertCount(1, $qg002);
    }

    public function test_issue_13_job_inheritance_and_backoff(): void
    {
        // 1. extends AppQueuedJob recognized as queued
        $customBaseJob = <<<'PHP'
<?php
namespace App\Jobs;

class ExportJob extends AppQueuedJob {
    public function handle(): void {}
}
PHP;
        $findings = $this->analyzer->analyseCode($customBaseJob, 'app/Jobs/ExportJob.php');
        $qg004 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG004);
        self::assertCount(1, $qg004);

        // 2. $backoff alone does NOT count as bounded retries
        $backoffJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class BackoffJob implements ShouldQueue {
    public int $timeout = 60;
    public int $backoff = 3; // delays retries, but does not bound them!

    public function handle(): void {}
}
PHP;
        $findings = $this->analyzer->analyseCode($backoffJob, 'app/Jobs/BackoffJob.php');
        $qg004 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG004);
        self::assertCount(1, $qg004);
        self::assertStringContainsString('$tries / retryUntil()', $qg004[array_key_first($qg004)]->message);
    }

    public function test_issue_16_syntax_error_reported_as_parse_error(): void
    {
        $brokenCode = <<<'PHP'
<?php
namespace App\Jobs;
class BrokenJob {
    public function handle(
PHP;
        $findings = $this->analyzer->analyseCode($brokenCode, 'app/Jobs/BrokenJob.php');
        self::assertCount(1, $findings);
        self::assertSame(RuleId::PARSE_ERROR, $findings[0]->ruleId);
    }

    public function test_issue_20_chunk_callbacks_inspected_and_sync_dispatch_differentiated(): void
    {
        // chunk(100, fn) callback inspected
        $chunkCode = <<<'PHP'
<?php
namespace App\Services;
use App\Models\User;
use App\Jobs\ProcessUser;

class ChunkService {
    public function process(): void {
        User::chunk(100, function ($users) {
            foreach ($users as $user) {
                ProcessUser::dispatch($user);
            }
        });
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($chunkCode, 'app/Services/ChunkService.php');
        $qg006 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG006);
        self::assertCount(1, $qg006);

        // dispatchSync inside loop has distinct blocking thread message
        $syncLoopCode = <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\ProcessUser;

class SyncLoopService {
    public function process(array $items): void {
        foreach ($items as $item) {
            ProcessUser::dispatchSync($item);
        }
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($syncLoopCode, 'app/Services/SyncLoopService.php');
        $qg006 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG006));
        self::assertCount(1, $qg006);
        self::assertStringContainsString('blocks thread execution sequentially', $qg006[0]->message);
    }

    public function test_issue_21_request_context_gaps(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;

class ContextJob implements ShouldQueue {
    public int $tries = 1;
    public function handle(): void {
        $user = Auth::guard('web')->user();
        $query = $_GET['search'] ?? null;
        $req = app('request');
        $currentUrl = url()->current();
        $this->helperMethod();
    }

    private function helperMethod(): void {
        $ip = request()->ip();
    }
}
PHP;
        $findings = $this->analyzer->analyseCode($code, 'app/Jobs/ContextJob.php');
        $qg009 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG009));
        // All 5 request contexts must be detected!
        self::assertGreaterThanOrEqual(5, count($qg009));
    }

    public function test_issue_22_batch_dispatch_after_response_and_top_level(): void
    {
        $code = <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;

// Top level batch dispatch with dispatchAfterResponse
Bus::batch([])->dispatchAfterResponse();
PHP;
        $findings = $this->analyzer->analyseCode($code, 'routes/web.php');
        $qg005 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG005));
        self::assertCount(1, $qg005);
    }

    public function test_issue_2_fixer_safety(): void
    {
        // 1. AddAfterCommitFixer does NOT touch dispatches outside transactions or sync events
        $code = <<<'PHP'
<?php
class EventService {
    public function fire(): void {
        UserRegistered::dispatch($user);
    }
}
PHP;
        $fixer = new AddAfterCommitFixer;
        $fixed = $fixer->fix($code);
        self::assertSame($code, $fixed);

        // 2. AddSerializesModelsFixer only adds trait when constructor accepts models, not scalars
        $scalarJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class ScalarJob implements ShouldQueue {
    public function __construct(public int $id, public string $status) {}
    public function handle(): void {}
}
PHP;
        $modelFixer = new AddSerializesModelsFixer;
        $fixedModel = $modelFixer->fix($scalarJob);
        self::assertSame($scalarJob, $fixedModel);

        // 3. AddFinallyStubFixer emits typed Batch $batch
        $batchCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\Bus;

class BatchService {
    public function run(): void {
        Bus::batch([])->dispatch();
    }
}
PHP;
        $finallyFixer = new AddFinallyStubFixer;
        $fixedBatch = $finallyFixer->fix($batchCode);
        self::assertStringContainsString('function (\Illuminate\Bus\Batch $batch)', $fixedBatch);
    }
}
