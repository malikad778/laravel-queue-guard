<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\Severity;

final class GithubAnnotationsReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            $type = match ($finding->severity) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Info => 'notice',
            };

            $file = $this->escapeProperty($finding->relativePath($projectRoot));
            $title = $this->escapeProperty($finding->ruleId->value);
            $message = $this->escapeData(sprintf('%s (%s)', $finding->message, $finding->tip));

            $lines[] = sprintf(
                '::%s file=%s,line=%d,title=%s::%s',
                $type,
                $file,
                $finding->line,
                $title,
                $message
            );
        }

        return implode("\n", $lines).($lines !== [] ? "\n" : '');
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(['%', ':', ',', "\r", "\n"], ['%25', '%3A', '%2C', '%0D', '%0A'], $value);
    }

    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }
}
