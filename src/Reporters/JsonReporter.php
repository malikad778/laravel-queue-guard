<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\Severity;

final class JsonReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $errors = 0;
        $warnings = 0;
        $items = [];

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Error) {
                $errors++;
            } elseif ($finding->severity === Severity::Warning) {
                $warnings++;
            }
            $items[] = $finding->toArray($projectRoot);
        }

        $payload = [
            'totals' => [
                'errors' => $errors,
                'warnings' => $warnings,
                'files' => $filesCount,
            ],
            'findings' => $items,
        ];

        return (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')."\n";
    }
}
