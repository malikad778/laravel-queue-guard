<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Console;

use Malikad\QueueGuard\Contracts\Reporter;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\FileCollector;
use Malikad\QueueGuard\Core\Severity;
use Malikad\QueueGuard\Fixers\AddAfterCommitFixer;
use Malikad\QueueGuard\Fixers\AddFinallyStubFixer;
use Malikad\QueueGuard\Fixers\AddSerializesModelsFixer;
use Malikad\QueueGuard\Reporters\CheckstyleReporter;
use Malikad\QueueGuard\Reporters\ConsoleReporter;
use Malikad\QueueGuard\Reporters\GithubAnnotationsReporter;
use Malikad\QueueGuard\Reporters\GitlabCodeQualityReporter;
use Malikad\QueueGuard\Reporters\JsonReporter;
use Malikad\QueueGuard\Reporters\JunitReporter;
use Malikad\QueueGuard\Reporters\SarifReporter;
use Malikad\QueueGuard\Standalone\StandaloneRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('analyse')
            ->setDescription('Run static analysis on Laravel queues, jobs, and transactions')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Paths to analyse')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to queue-guard.php config file')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: console, json, sarif, github, checkstyle, junit, gitlab')
            ->addOption('baseline', 'b', InputOption::VALUE_REQUIRED, 'Path to baseline file')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Fail threshold: error, warning, info')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Apply safe autofixes before reporting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '';
        /** @var string|null $configOpt */
        $configOpt = $input->getOption('config');
        $configPath = $configOpt ?? ($cwd.'/queue-guard.php');
        if (! is_file($configPath) && is_file($cwd.'/config/queue-guard.php')) {
            $configPath = $cwd.'/config/queue-guard.php';
        }

        $config = Config::fromFile($configPath, $cwd);

        /** @var list<string> $argPaths */
        $argPaths = $input->getArgument('paths');
        $targetPaths = $argPaths !== [] ? $argPaths : $config->paths;

        if ((bool) $input->getOption('fix')) {
            $this->applyFixers($targetPaths, $config);
        }

        /** @var string|null $baselineOpt */
        $baselineOpt = $input->getOption('baseline');

        $runner = new StandaloneRunner;
        $result = $runner->run($config, $targetPaths, $baselineOpt);

        /** @var string|null $formatOpt */
        $formatOpt = $input->getOption('format');
        $format = $formatOpt ?? $config->format;

        $reporter = $this->resolveReporter($format);
        $output->write($reporter->format($result['findings'], $result['filesCount'], $result['elapsedSeconds'], $cwd));

        /** @var string|null $failOnOpt */
        $failOnOpt = $input->getOption('fail-on');
        $failThreshold = $failOnOpt !== null ? (Severity::tryFrom($failOnOpt) ?? $config->failOn) : $config->failOn;

        foreach ($result['findings'] as $finding) {
            if ($finding->severity->meetsThreshold($failThreshold)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $paths
     */
    private function applyFixers(array $paths, Config $config): void
    {
        $collector = new FileCollector;
        $files = $collector->collect($paths, $config->exclude, $config->projectRoot);
        $fixers = [
            new AddSerializesModelsFixer,
            new AddFinallyStubFixer,
            new AddAfterCommitFixer,
        ];

        foreach ($files as $file) {
            $original = @file_get_contents($file);
            if ($original === false) {
                continue;
            }
            $updated = $original;
            foreach ($fixers as $fixer) {
                if ($config->isRuleEnabled($fixer->supports())) {
                    $updated = $fixer->fix($updated);
                }
            }
            if ($updated !== $original) {
                file_put_contents($file, $updated);
            }
        }
    }

    private function resolveReporter(string $format): Reporter
    {
        return match (strtolower($format)) {
            'json' => new JsonReporter,
            'sarif' => new SarifReporter,
            'github' => new GithubAnnotationsReporter,
            'checkstyle' => new CheckstyleReporter,
            'junit' => new JunitReporter,
            'gitlab' => new GitlabCodeQualityReporter,
            default => new ConsoleReporter,
        };
    }
}
