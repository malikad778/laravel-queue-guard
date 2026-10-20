<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\Severity;

final class ConsoleReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $lines = [];
        $errors = 0;
        $warnings = 0;
        $infos = 0;

        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Error) {
                $errors++;
            } elseif ($finding->severity === Severity::Warning) {
                $warnings++;
            } elseif ($finding->severity === Severity::Info) {
                $infos++;
            }

            $relFile = $finding->relativePath($projectRoot);
            $sevLabel = str_pad($finding->severity->value, 7);

            $lines[] = sprintf(' %s  %s %s:%d', $finding->ruleId->value, $sevLabel, $relFile, $finding->line);
            $lines[] = sprintf('        %s', $finding->message);
            $lines[] = sprintf('        tip: %s', $finding->tip);
            $lines[] = sprintf('        docs: %s', $finding->getDocsUrl());
            $lines[] = '';
        }

        $summaryParts = [
            sprintf('%d %s', $errors, $errors === 1 ? 'error' : 'errors'),
            sprintf('%d %s', $warnings, $warnings === 1 ? 'warning' : 'warnings'),
        ];
        if ($infos > 0) {
            $summaryParts[] = sprintf('%d %s', $infos, $infos === 1 ? 'info' : 'infos');
        }

        $lines[] = sprintf(
            ' %s in %d %s (%.2fs)',
            implode(', ', $summaryParts),
            $filesCount,
            $filesCount === 1 ? 'file' : 'files',
            $elapsedSeconds
        );

        return implode("\n", $lines)."\n";
    }
}
