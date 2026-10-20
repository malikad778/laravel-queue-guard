<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;

final class CheckstyleReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $grouped[$finding->relativePath($projectRoot)][] = $finding;
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<checkstyle version="1.0.0">'];
        foreach ($grouped as $file => $fileFindings) {
            $escapedFile = htmlspecialchars($file, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml[] = "  <file name=\"{$escapedFile}\">";
            foreach ($fileFindings as $f) {
                $msg = htmlspecialchars($f->message.' ('.$f->tip.')', ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $source = htmlspecialchars($f->ruleId->value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $sev = htmlspecialchars($f->severity->value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml[] = "    <error line=\"{$f->line}\" severity=\"{$sev}\" message=\"{$msg}\" source=\"{$source}\" />";
            }
            $xml[] = '  </file>';
        }
        $xml[] = '</checkstyle>';

        return implode("\n", $xml)."\n";
    }
}
