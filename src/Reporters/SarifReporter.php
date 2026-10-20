<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Reporters;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use Malikad\QueueGuard\QueueGuard;

final class SarifReporter implements Reporter
{
    public function format(array $findings, int $filesCount, float $elapsedSeconds, string $projectRoot = ''): string
    {
        $rules = [];
        foreach (RuleId::cases() as $ruleId) {
            $defaultLevel = match ($ruleId->defaultSeverity()) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Info => 'note',
            };

            $rules[] = [
                'id' => $ruleId->value,
                'name' => $ruleId->phpstanIdentifier(),
                'shortDescription' => [
                    'text' => $ruleId->title(),
                ],
                'defaultConfiguration' => [
                    'level' => $defaultLevel,
                ],
                'helpUri' => $ruleId->docsUrl(),
            ];
        }

        $results = [];
        foreach ($findings as $finding) {
            $level = match ($finding->severity) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Info => 'note',
            };

            $results[] = [
                'ruleId' => $finding->ruleId->value,
                'level' => $level,
                'message' => [
                    'text' => $finding->message.' Tip: '.$finding->tip,
                ],
                'partialFingerprints' => [
                    'primaryLocationLineHash' => $finding->fingerprint($projectRoot),
                ],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $finding->relativePath($projectRoot),
                                'uriBaseId' => '%SRCROOT%',
                            ],
                            'region' => [
                                'startLine' => max(1, $finding->line),
                            ],
                        ],
                    ],
                ],
            ];
        }

        $sarif = [
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'laravel-queue-guard',
                            'informationUri' => 'https://github.com/malikad778/laravel-queue-guard',
                            'version' => QueueGuard::VERSION,
                            'rules' => $rules,
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];

        return (json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')."\n";
    }
}
