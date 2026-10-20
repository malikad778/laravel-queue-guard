<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Console;

use Malikad\QueueGuard\Core\RuleId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExplainCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('explain')
            ->setDescription('Explain a QueueGuard rule with bad and good examples')
            ->addArgument('rule', InputArgument::REQUIRED, 'Rule ID (e.g. QG001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $ruleArg */
        $ruleArg = $input->getArgument('rule');
        $ruleId = RuleId::tryFrom(strtoupper(trim($ruleArg)));

        if ($ruleId === null) {
            $output->writeln("<error>Unknown rule ID: {$ruleArg}</error>");

            return Command::FAILURE;
        }

        $details = $ruleId->explanation();

        $output->writeln('');
        $output->writeln(sprintf(' <info>%s</info>  %s', $ruleId->value, $ruleId->title()));
        $output->writeln(sprintf(' PHPStan ID: %s | Default Severity: %s', $ruleId->phpstanIdentifier(), $ruleId->defaultSeverity()->value));
        $output->writeln('');
        $output->writeln(' <comment>Why it matters:</comment>');
        $output->writeln(' '.$details['why']);
        $output->writeln('');
        $output->writeln(' <comment>Bad:</comment>');
        $output->writeln($details['bad']);
        $output->writeln('');
        $output->writeln(' <comment>Good:</comment>');
        $output->writeln($details['good']);
        $output->writeln('');
        $output->writeln(' Docs: '.$ruleId->docsUrl());
        $output->writeln('');

        return Command::SUCCESS;
    }
}
