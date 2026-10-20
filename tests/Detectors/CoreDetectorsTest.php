<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Detectors;

use Malikad\QueueGuard\Core\Analyzer;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use PHPUnit\Framework\TestCase;

final class CoreDetectorsTest extends TestCase
{
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new Analyzer;
    }

    public function test_q_g001_detects_http_inside_transaction_closure_and_helper_and_allows_after_commit(): void
    {
        $badCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CheckoutService
{
    public function checkout(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            Http::post('https://api.stripe.com/v1/charges', $payload);
            $this->notifyWebhook($payload);
        });
    }

    private function notifyWebhook(array $payload): void
    {
        Http::post('https://hooks.example.com/order', $payload);
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badCode, 'app/Services/CheckoutService.php');
        $qg001 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG001));
        self::assertCount(2, $qg001);

        $goodCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CheckoutService
{
    public function checkout(array $payload): void
    {
        DB::transaction(function () use ($payload) {
            DB::afterCommit(fn () => Http::post('https://api.stripe.com/v1/charges', $payload));
        });
    }
}
PHP;

        $goodFindings = $this->analyzer->analyseCode($goodCode, 'app/Services/CheckoutService.php');
        self::assertCount(0, $goodFindings);
    }

    public function test_q_g002_detects_non_idempotent_stripe_charge_and_downgrades_unique_lock_to_warning(): void
    {
        $badJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stripe\Charge;

class ChargeCustomer implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 60;

    public function handle(): void
    {
        Charge::create(['amount' => 2000, 'currency' => 'usd']);
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badJob, 'app/Jobs/ChargeCustomer.php');
        $qg002 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG002));
        self::assertCount(1, $qg002);
        self::assertSame(Severity::Error, $qg002[0]->severity);

        $uniqueOnlyJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Stripe\Charge;

class ChargeCustomerUnique implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 3;
    public int $timeout = 60;

    public function handle(): void
    {
        Charge::create(['amount' => 2000, 'currency' => 'usd']);
    }
}
PHP;

        $uniqueFindings = $this->analyzer->analyseCode($uniqueOnlyJob, 'app/Jobs/ChargeCustomerUnique.php');
        $qg002Unique = array_values(array_filter($uniqueFindings, static fn ($f) => $f->ruleId === RuleId::QG002));
        self::assertCount(1, $qg002Unique);
        self::assertSame(Severity::Warning, $qg002Unique[0]->severity);

        $goodJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Stripe\Charge;

class ChargeCustomerSafe implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 60;

    public function handle(): void
    {
        Charge::create(
            ['amount' => 2000, 'currency' => 'usd'],
            ['idempotency_key' => 'order_123']
        );
    }
}
PHP;

        $goodFindings = $this->analyzer->analyseCode($goodJob, 'app/Jobs/ChargeCustomerSafe.php');
        $qg002Good = array_values(array_filter($goodFindings, static fn ($f) => $f->ruleId === RuleId::QG002));
        self::assertCount(0, $qg002Good);
    }

    public function test_q_g003_detects_missing_serializes_models(): void
    {
        $badJob = <<<'PHP'
<?php
namespace App\Jobs;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessOrder implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public Order $order) {}
}
PHP;

        $findings = $this->analyzer->analyseCode($badJob, 'app/Jobs/ProcessOrder.php');
        $qg003 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG003));
        self::assertCount(1, $qg003);
    }

    public function test_q_g004_detects_unbounded_job_timeout(): void
    {
        $badJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateReport implements ShouldQueue
{
    public function handle(): void {}
}
PHP;

        $findings = $this->analyzer->analyseCode($badJob, 'app/Jobs/GenerateReport.php');
        $qg004 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG004));
        self::assertCount(1, $qg004);
    }

    public function test_q_g005_detects_batch_without_finally(): void
    {
        $badCode = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\Bus;

class BatchDispatcher
{
    public function run(array $jobs): void
    {
        Bus::batch($jobs)->dispatch();
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badCode, 'app/Services/BatchDispatcher.php');
        $qg005 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG005));
        self::assertCount(1, $qg005);
    }

    public function test_q_g006_detects_sync_dispatch_in_loop(): void
    {
        $badCode = <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendNewsletter;

class BlastService
{
    public function blast(iterable $users): void
    {
        foreach ($users as $user) {
            SendNewsletter::dispatch($user);
        }
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badCode, 'app/Services/BlastService.php');
        $qg006 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG006));
        self::assertCount(1, $qg006);
    }

    public function test_q_g007_detects_dispatch_inside_transaction_without_after_commit(): void
    {
        $badCode = <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendWelcomeEmail;
use Illuminate\Support\Facades\DB;

class SignupService
{
    public function register(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            SendWelcomeEmail::dispatch($userId);
        });
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badCode, 'app/Services/SignupService.php');
        $qg007 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG007));
        self::assertCount(1, $qg007);
    }

    public function test_q_g008_detects_timeout_greater_or_equal_retry_after(): void
    {
        $badJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class HeavyExport implements ShouldQueue
{
    public int $tries = 2;
    public int $timeout = 120;

    public function handle(): void {}
}
PHP;

        $findings = $this->analyzer->analyseCode($badJob, 'app/Jobs/HeavyExport.php', new Config);
        $qg008 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG008));
        self::assertCount(1, $qg008);
    }

    public function test_q_g009_detects_request_context_inside_queued_handle(): void
    {
        $badJob = <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;

class AuditAction implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 30;

    public function handle(): void
    {
        $ip = request()->ip();
    }
}
PHP;

        $findings = $this->analyzer->analyseCode($badJob, 'app/Jobs/AuditAction.php');
        $qg009 = array_values(array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG009));
        self::assertCount(1, $qg009);
    }
}
