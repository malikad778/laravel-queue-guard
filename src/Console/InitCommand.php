<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize config/queue-guard.php configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '.';

        // In a Laravel app, write to config/queue-guard.php; otherwise root queue-guard.php
        $target = is_dir($cwd.'/config')
            ? $cwd.'/config/queue-guard.php'
            : $cwd.'/queue-guard.php';

        if (is_file($target)) {
            $displayPath = is_dir($cwd.'/config') ? 'config/queue-guard.php' : 'queue-guard.php';
            $output->writeln("<comment>{$displayPath} already exists.</comment>");

            return Command::SUCCESS;
        }

        $source = __DIR__.'/../../config/queue-guard.php';
        if (is_file($source)) {
            copy($source, $target);
            $displayPath = is_dir($cwd.'/config') ? 'config/queue-guard.php' : 'queue-guard.php';
            $output->writeln("<info>Created {$displayPath}.</info>");

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }
}
