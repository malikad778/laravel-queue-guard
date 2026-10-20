<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

final class Config
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $exclude
     * @param  array<string, array{enabled?: bool, severity?: string}|bool>  $rules
     * @param  list<string>  $externalCallClasses
     * @param  list<string>  $financialCallClasses
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $paths = ['app'],
        public readonly array $exclude = ['vendor', 'tests'],
        public readonly array $rules = [],
        public readonly array $externalCallClasses = [],
        public readonly array $financialCallClasses = [],
        public readonly int $maxCallDepth = 2,
        public readonly ?string $baseline = null,
        public readonly string $format = 'console',
        public readonly Severity $failOn = Severity::Error,
        public readonly string $projectRoot = '',
        public readonly array $warnings = [],
    ) {}

    public static function fromFile(?string $configFile, string $projectRoot = ''): self
    {
        if ($configFile === null || ! is_file($configFile)) {
            return new self(projectRoot: $projectRoot);
        }

        /** @var mixed $raw */
        $raw = require $configFile;
        if (! is_array($raw)) {
            return new self(projectRoot: $projectRoot);
        }

        $warnings = [];

        $failOnStr = isset($raw['failOn']) && is_string($raw['failOn']) ? $raw['failOn'] : 'error';
        $failOn = Severity::tryFrom($failOnStr);
        if ($failOn === null) {
            $warnings[] = "Unknown failOn '{$failOnStr}' in configuration, falling back to 'error'.";
            $failOn = Severity::Error;
        }

        $format = isset($raw['format']) && is_string($raw['format']) ? $raw['format'] : 'console';
        $validFormats = ['console', 'json', 'github', 'sarif', 'checkstyle', 'gitlab', 'markdown'];
        if (! in_array($format, $validFormats, true)) {
            $warnings[] = "Unknown format '{$format}' in configuration, falling back to 'console'.";
            $format = 'console';
        }

        $toStringList = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                if (is_string($item)) {
                    $result[] = $item;
                }
            }

            return $result;
        };

        /** @var array<string, array{enabled?: bool, severity?: string}|bool> $rules */
        $rules = [];
        if (isset($raw['rules']) && is_array($raw['rules'])) {
            foreach ($raw['rules'] as $ruleKey => $ruleVal) {
                if (! is_string($ruleKey)) {
                    continue;
                }
                if (RuleId::tryFrom($ruleKey) === null) {
                    $warnings[] = "Unknown rule ID '{$ruleKey}' in configuration.";
                }
                if (is_bool($ruleVal)) {
                    $rules[$ruleKey] = $ruleVal;
                } elseif (is_array($ruleVal)) {
                    /** @var array{enabled?: bool, severity?: string} $entry */
                    $entry = [];
                    if (isset($ruleVal['enabled']) && is_bool($ruleVal['enabled'])) {
                        $entry['enabled'] = $ruleVal['enabled'];
                    }
                    if (isset($ruleVal['severity']) && is_string($ruleVal['severity'])) {
                        $entry['severity'] = $ruleVal['severity'];
                    }
                    $rules[$ruleKey] = $entry;
                }
            }
        }

        return new self(
            paths: isset($raw['paths']) && is_array($raw['paths']) ? $toStringList($raw['paths']) : ['app'],
            exclude: isset($raw['exclude']) && is_array($raw['exclude']) ? $toStringList($raw['exclude']) : ['vendor', 'tests'],
            rules: $rules,
            externalCallClasses: isset($raw['externalCallClasses']) && is_array($raw['externalCallClasses'])
                ? $toStringList($raw['externalCallClasses'])
                : [],
            financialCallClasses: isset($raw['financialCallClasses']) && is_array($raw['financialCallClasses'])
                ? $toStringList($raw['financialCallClasses'])
                : [],
            maxCallDepth: isset($raw['maxCallDepth']) && is_int($raw['maxCallDepth']) ? $raw['maxCallDepth'] : 2,
            baseline: isset($raw['baseline']) && is_string($raw['baseline']) ? $raw['baseline'] : null,
            format: $format,
            failOn: $failOn,
            projectRoot: $projectRoot,
            warnings: $warnings,
        );
    }

    public function isRuleEnabled(RuleId $ruleId): bool
    {
        $cfg = $this->rules[$ruleId->value] ?? null;
        if ($cfg === null) {
            return true;
        }

        if (is_bool($cfg)) {
            return $cfg;
        }

        if (is_array($cfg)) {
            return (bool) ($cfg['enabled'] ?? true);
        }

        return true;
    }

    public function severityFor(RuleId $ruleId, ?Severity $detectorSeverity = null): Severity
    {
        $cfg = $this->rules[$ruleId->value] ?? null;
        if (is_array($cfg) && isset($cfg['severity']) && is_string($cfg['severity'])) {
            $override = Severity::tryFrom($cfg['severity']);
            if ($override !== null) {
                return $override;
            }
        }

        return $detectorSeverity ?? $ruleId->defaultSeverity();
    }
}
