<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Standalone;

use Malikad\QueueGuard\Core\Analyzer;
use Malikad\QueueGuard\Core\Baseline;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\FileCollector;
use Malikad\QueueGuard\Core\Finding;

final class StandaloneRunner
{
    public function __construct(
        private readonly FileCollector $fileCollector = new FileCollector,
        private readonly Analyzer $analyzer = new Analyzer,
    ) {}

    /**
     * @param  list<string>|null  $overridePaths
     * @return array{findings: list<Finding>, filesCount: int, elapsedSeconds: float}
     */
    public function run(Config $config, ?array $overridePaths = null, ?string $baselinePath = null): array
    {
        $start = microtime(true);
        $paths = $overridePaths !== null && $overridePaths !== [] ? $overridePaths : $config->paths;
        $files = $this->fileCollector->collect($paths, $config->exclude, $config->projectRoot);

        $findings = $this->analyzer->analyseFiles($files, $config);

        $effectiveBaseline = $baselinePath ?? $config->baseline;
        if ($effectiveBaseline !== null && $effectiveBaseline !== '') {
            $resolvedBaseline = $config->projectRoot !== '' && ! is_file($effectiveBaseline)
                ? rtrim($config->projectRoot, '/\\').DIRECTORY_SEPARATOR.$effectiveBaseline
                : $effectiveBaseline;
            $baseline = Baseline::load($resolvedBaseline);
            $findings = $baseline->filter($findings, $config->projectRoot);
        }

        $elapsed = microtime(true) - $start;

        return [
            'findings' => $findings,
            'filesCount' => count($files),
            'elapsedSeconds' => $elapsed,
        ];
    }
}
