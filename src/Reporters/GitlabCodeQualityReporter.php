<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\Severity;

final class GitlabCodeQualityReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $items = [];
        foreach ($findings as $f) {
            $severity = match ($f->severity) {
                Severity::Error => 'major',
                Severity::Warning => 'minor',
                Severity::Info => 'info',
            };

            $items[] = [
                'description' => $f->message,
                'check_name' => $f->ruleId->value,
                'fingerprint' => $f->fingerprint($projectRoot),
                'severity' => $severity,
                'location' => [
                    'path' => $f->relativePath($projectRoot),
                    'lines' => [
                        'begin' => $f->line,
                    ],
                ],
            ];
        }

        return (json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')."\n";
    }
}
