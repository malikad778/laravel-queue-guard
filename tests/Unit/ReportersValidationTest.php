<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use Malikad\QueueGuard\QueueGuard;
use Malikad\QueueGuard\Reporters\ConsoleReporter;
use Malikad\QueueGuard\Reporters\GithubAnnotationsReporter;
use Malikad\QueueGuard\Reporters\SarifReporter;
use PHPUnit\Framework\TestCase;

final class ReportersValidationTest extends TestCase
{
    public function test_console_reporter_includes_info_findings_in_summary(): void
    {
        $findings = [
            new Finding(
                ruleId: RuleId::QG004,
                severity: Severity::Info,
                file: 'app/Jobs/SyncUsers.php',
                line: 10,
                message: 'Queued class SyncUsers is missing explicit $timeout (covered by Horizon).',
                tip: 'Set explicit timeout.',
            ),
        ];

        $reporter = new ConsoleReporter;
        $output = $reporter->format($findings, 1, 0.42);

        self::assertStringContainsString('0 errors, 0 warnings, 1 info in 1 file (0.42s)', $output);
    }

    public function test_github_annotations_reporter_escapes_special_characters(): void
    {
        $findings = [
            new Finding(
                ruleId: RuleId::QG001,
                severity: Severity::Error,
                file: 'app/Services/Special:Path,Name.php',
                line: 15,
                message: "Line 1\nLine 2 with 100% discount",
                tip: 'Wrap in DB::afterCommit().',
            ),
        ];

        $reporter = new GithubAnnotationsReporter;
        $output = $reporter->format($findings, 1, 0.5);

        // ':' must be escaped to '%3A', ',' must be escaped to '%2C' in properties
        self::assertStringContainsString('file=app/Services/Special%3APath%2CName.php', $output);
        // '%' must be escaped to '%25', '\n' to '%0A'
        self::assertStringContainsString('Line 1%0ALine 2 with 100%25 discount', $output);
    }

    public function test_sarif_reporter_conforms_to_sarif_spec(): void
    {
        $findings = [
            new Finding(
                ruleId: RuleId::QG001,
                severity: Severity::Error,
                file: 'app/Services/Checkout.php',
                line: 20,
                message: 'HTTP inside transaction.',
                tip: 'Use afterCommit.',
            ),
        ];

        $reporter = new SarifReporter;
        $json = $reporter->format($findings, 1, 0.5);
        $data = json_decode($json, true);
        assert(is_array($data));

        self::assertSame(QueueGuard::VERSION, $data['runs'][0]['tool']['driver']['version']);
        self::assertArrayHasKey('defaultConfiguration', $data['runs'][0]['tool']['driver']['rules'][0]);
        self::assertSame('%SRCROOT%', $data['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uriBaseId']);
        self::assertArrayHasKey('partialFingerprints', $data['runs'][0]['results'][0]);
    }
}
