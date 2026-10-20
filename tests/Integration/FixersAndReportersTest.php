<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Integration;

use Malikad\QueueGuard\Core\Analyzer;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Fixers\AddAfterCommitFixer;
use Malikad\QueueGuard\Fixers\AddFinallyStubFixer;
use Malikad\QueueGuard\Fixers\AddSerializesModelsFixer;
use Malikad\QueueGuard\Reporters\SarifReporter;
use PHPUnit\Framework\TestCase;

final class FixersAndReportersTest extends TestCase
{
    public function test_add_serializes_models_fixer_clears_q_g003(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Jobs;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendReceipt implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public Order $order) {}
}
PHP;

        $fixer = new AddSerializesModelsFixer;
        $fixed = $fixer->fix($code);

        $analyzer = new Analyzer;
        $findings = $analyzer->analyseCode($fixed, 'app/Jobs/SendReceipt.php');
        $qg003 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG003);

        self::assertCount(0, $qg003);
    }

    public function test_add_finally_stub_fixer_clears_q_g005(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\Bus;

class BatchRunner
{
    public function run(array $jobs): void
    {
        Bus::batch($jobs)->dispatch();
    }
}
PHP;

        $fixer = new AddFinallyStubFixer;
        $fixed = $fixer->fix($code);

        $analyzer = new Analyzer;
        $findings = $analyzer->analyseCode($fixed, 'app/Services/BatchRunner.php');
        $qg005 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG005);

        self::assertCount(0, $qg005);
    }

    public function test_add_after_commit_fixer_clears_q_g007(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SendOrderEmail;
use Illuminate\Support\Facades\DB;

class OrderCreator
{
    public function create(int $id): void
    {
        DB::transaction(function () use ($id) {
            SendOrderEmail::dispatch($id);
        });
    }
}
PHP;

        $fixer = new AddAfterCommitFixer;
        $fixed = $fixer->fix($code);

        $analyzer = new Analyzer;
        $findings = $analyzer->analyseCode($fixed, 'app/Services/OrderCreator.php');
        $qg007 = array_filter($findings, static fn ($f) => $f->ruleId === RuleId::QG007);

        self::assertCount(0, $qg007);
    }

    public function test_sarif_reporter_outputs_valid_sarif210_structure(): void
    {
        $analyzer = new Analyzer;
        $code = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WebhookService
{
    public function send(): void
    {
        DB::transaction(function () {
            Http::post('https://example.com/hook');
        });
    }
}
PHP;
        $findings = $analyzer->analyseCode($code, 'app/Services/WebhookService.php');
        $reporter = new SarifReporter;
        $json = $reporter->format($findings, 1, 0.05);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        self::assertSame('2.1.0', $decoded['version']);
        self::assertNotEmpty($decoded['runs'][0]['results']);
    }
}
