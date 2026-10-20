<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

final class Baseline
{
    /** @var array<string, int> Map of fingerprint to count */
    private array $fingerprintCounts = [];

    /**
     * @param  list<array{ruleId: string, file: string, message: string, fingerprint: string, occurrence?: int}>  $entries
     */
    public function __construct(
        private readonly array $entries = [],
    ) {
        foreach ($this->entries as $entry) {
            $fp = $entry['fingerprint'];
            $this->fingerprintCounts[$fp] = ($this->fingerprintCounts[$fp] ?? 0) + 1;
        }
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self([]);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return new self([]);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ! isset($decoded['findings']) || ! is_array($decoded['findings'])) {
            return new self([]);
        }

        $entries = [];
        foreach ($decoded['findings'] as $item) {
            if (
                is_array($item)
                && isset($item['ruleId'], $item['file'], $item['message'], $item['fingerprint'])
                && is_string($item['ruleId'])
                && is_string($item['file'])
                && is_string($item['message'])
                && is_string($item['fingerprint'])
            ) {
                $entries[] = [
                    'ruleId' => $item['ruleId'],
                    'file' => $item['file'],
                    'message' => $item['message'],
                    'fingerprint' => $item['fingerprint'],
                    'occurrence' => isset($item['occurrence']) && is_int($item['occurrence']) ? $item['occurrence'] : 0,
                ];
            }
        }

        return new self($entries);
    }

    /**
     * @param  list<Finding>  $findings
     */
    public static function write(string $path, array $findings, string $projectRoot = ''): void
    {
        $items = [];
        $occurrences = [];

        foreach ($findings as $finding) {
            $baseKey = $finding->ruleId->value.'|'.$finding->relativePath($projectRoot).'|'.(preg_replace('/\s+/', ' ', trim($finding->message)) ?? $finding->message);
            $idx = $occurrences[$baseKey] ?? 0;
            $occurrences[$baseKey] = $idx + 1;

            $items[] = [
                'ruleId' => $finding->ruleId->value,
                'file' => $finding->relativePath($projectRoot),
                'message' => $finding->message,
                'occurrence' => $idx,
                'fingerprint' => $finding->fingerprint($projectRoot, $idx),
            ];
        }

        $payload = [
            'version' => 2,
            'count' => count($items),
            'findings' => $items,
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function filter(array $findings, string $projectRoot = ''): array
    {
        if ($this->fingerprintCounts === []) {
            return $findings;
        }

        $remaining = [];
        $available = $this->fingerprintCounts;
        $occurrences = [];

        foreach ($findings as $finding) {
            $baseKey = $finding->ruleId->value.'|'.$finding->relativePath($projectRoot).'|'.(preg_replace('/\s+/', ' ', trim($finding->message)) ?? $finding->message);
            $idx = $occurrences[$baseKey] ?? 0;
            $occurrences[$baseKey] = $idx + 1;

            $fp = $finding->fingerprint($projectRoot, $idx);
            $legacyFp = $finding->fingerprint($projectRoot, 0);

            if (isset($available[$fp]) && $available[$fp] > 0) {
                $available[$fp]--;
            } elseif (isset($available[$legacyFp]) && $available[$legacyFp] > 0) {
                $available[$legacyFp]--;
            } else {
                $remaining[] = $finding;
            }
        }

        return $remaining;
    }

    /**
     * Identifies baseline entries that did not match any current finding (stale entries).
     *
     * @param  list<Finding>  $findings
     * @return list<array{ruleId: string, file: string, message: string, fingerprint: string}>
     */
    public function getStaleEntries(array $findings, string $projectRoot = ''): array
    {
        $occurrences = [];
        $matched = [];

        foreach ($findings as $finding) {
            $baseKey = $finding->ruleId->value.'|'.$finding->relativePath($projectRoot).'|'.(preg_replace('/\s+/', ' ', trim($finding->message)) ?? $finding->message);
            $idx = $occurrences[$baseKey] ?? 0;
            $occurrences[$baseKey] = $idx + 1;

            $fp = $finding->fingerprint($projectRoot, $idx);
            $legacyFp = $finding->fingerprint($projectRoot, 0);

            $matched[$fp] = ($matched[$fp] ?? 0) + 1;
            $matched[$legacyFp] = ($matched[$legacyFp] ?? 0) + 1;
        }

        $stale = [];
        $seen = [];
        foreach ($this->entries as $entry) {
            $fp = $entry['fingerprint'];
            $countUsed = $seen[$fp] ?? 0;
            $totalMatched = $matched[$fp] ?? 0;

            if ($countUsed >= $totalMatched) {
                $stale[] = $entry;
            } else {
                $seen[$fp] = $countUsed + 1;
            }
        }

        return $stale;
    }
}
