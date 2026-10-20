<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\Baseline;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use PHPUnit\Framework\TestCase;

final class BaselineOccurrenceTest extends TestCase
{
    public function test_multiple_identical_findings_in_same_file_are_preserved(): void
    {
        $finding1 = new Finding(
            ruleId: RuleId::QG001,
            severity: Severity::Error,
            file: 'app/Services/OrderService.php',
            line: 25,
            message: 'External call Http::post() inside a database transaction holds row locks.',
            tip: 'Move after commit.',
        );

        $finding2 = new Finding(
            ruleId: RuleId::QG001,
            severity: Severity::Error,
            file: 'app/Services/OrderService.php',
            line: 45,
            message: 'External call Http::post() inside a database transaction holds row locks.',
            tip: 'Move after commit.',
        );

        // Occurrence index 0 vs 1 yields different fingerprints
        self::assertNotSame($finding1->fingerprint('', 0), $finding2->fingerprint('', 1));

        $tmp = tempnam(sys_get_temp_dir(), 'qg_base_');
        assert(is_string($tmp));

        // Write baseline containing ONLY 1 occurrence
        Baseline::write($tmp, [$finding1]);

        $baseline = Baseline::load($tmp);
        unlink($tmp);

        // If code has 2 occurrences, exactly 1 should remain!
        $remaining = $baseline->filter([$finding1, $finding2]);
        self::assertCount(1, $remaining);
        self::assertSame(45, $remaining[0]->line);
    }

    public function test_get_stale_entries(): void
    {
        $finding = new Finding(
            ruleId: RuleId::QG001,
            severity: Severity::Error,
            file: 'app/Services/OrderService.php',
            line: 25,
            message: 'Old error message',
            tip: 'tip',
        );

        $baseline = new Baseline([
            [
                'ruleId' => 'QG001',
                'file' => 'app/Services/OrderService.php',
                'message' => 'Old error message',
                'fingerprint' => $finding->fingerprint('', 0),
                'occurrence' => 0,
            ],
            [
                'ruleId' => 'QG002',
                'file' => 'app/Jobs/DeletedJob.php',
                'message' => 'Non-existent job',
                'fingerprint' => 'abc12345',
                'occurrence' => 0,
            ],
        ]);

        // When finding matches the first entry, the second entry should be identified as stale
        $stale = $baseline->getStaleEntries([$finding]);
        self::assertCount(1, $stale);
        self::assertSame('QG002', $stale[0]['ruleId']);
    }
}
