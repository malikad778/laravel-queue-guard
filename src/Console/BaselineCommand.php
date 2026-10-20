<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Console;

use Malikad\QueueGuard\Core\Baseline;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Standalone\StandaloneRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BaselineCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('baseline')
            ->setDescription('Generate a queue-guard-baseline.json file from current findings')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Paths to scan')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to queue-guard.php config file')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output baseline file path', 'queue-guard-baseline.json');
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

        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        /** @var string $outFile */
        $outFile = $input->getOption('output');

        $runner = new StandaloneRunner;
        $result = $runner->run($config, $paths !== [] ? $paths : null, '');

        Baseline::write($outFile, $result['findings'], $cwd);
        $count = count($result['findings']);

        $output->writeln("<info>Wrote {$count} findings to {$outFile}</info>");

        return Command::SUCCESS;
    }
}
