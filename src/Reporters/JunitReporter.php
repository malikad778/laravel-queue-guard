<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;

final class JunitReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $failures = count($findings);
        $tests = max(1, $failures);
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf('<testsuites name="laravel-queue-guard" tests="%d" failures="%d" time="%.4f">', $tests, $failures, $elapsedSeconds),
            sprintf('  <testsuite name="QueueGuard" tests="%d" failures="%d">', $tests, $failures),
        ];

        if ($findings === []) {
            $xml[] = '    <testcase name="queue-guard" assertions="1" />';
        } else {
            foreach ($findings as $f) {
                $file = htmlspecialchars($f->relativePath($projectRoot), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $msg = htmlspecialchars($f->message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml[] = "    <testcase name=\"{$f->ruleId->value} at {$file}:{$f->line}\">";
                $xml[] = "      <failure type=\"{$f->ruleId->value}\" message=\"{$msg}\" />";
                $xml[] = '    </testcase>';
            }
        }

        $xml[] = '  </testsuite>';
        $xml[] = '</testsuites>';

        return implode("\n", $xml)."\n";
    }
}
