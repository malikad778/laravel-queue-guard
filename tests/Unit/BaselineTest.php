<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\Baseline;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use PHPUnit\Framework\TestCase;

final class BaselineTest extends TestCase
{
    public function test_filters_findings_matching_rule_file_and_normalized_message_regardless_of_line(): void
    {
        $f1 = new Finding(
            RuleId::QG001,
            Severity::Error,
            '/project/app/Services/PayService.php',
            42,
            'External call Http::post() inside a database transaction holds row locks during network I/O.',
            'Move the call after commit'
        );

        $fMovedLine = new Finding(
            RuleId::QG001,
            Severity::Error,
            '/project/app/Services/PayService.php',
            68,
            'External call Http::post() inside a database transaction holds row locks during network I/O.',
            'Move the call after commit'
        );

        $tmpFile = sys_get_temp_dir().'/qg-baseline-'.uniqid().'.json';
        Baseline::write($tmpFile, [$f1], '/project');

        $loaded = Baseline::load($tmpFile);
        $remaining = $loaded->filter([$fMovedLine], '/project');

        @unlink($tmpFile);
        self::assertCount(0, $remaining);
    }
}
