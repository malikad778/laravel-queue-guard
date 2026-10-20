<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Console;

use Malikad\QueueGuard\Core\RuleId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListRulesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('list-rules')
            ->setDescription('List all available QueueGuard rules')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $format */
        $format = $input->getOption('format');
        $rules = RuleId::qgRules();

        if ($format === 'json') {
            $catalog = [];
            foreach ($rules as $rule) {
                $catalog[] = [
                    'id' => $rule->value,
                    'identifier' => $rule->phpstanIdentifier(),
                    'defaultSeverity' => $rule->defaultSeverity()->value,
                    'title' => $rule->title(),
                    'docsUrl' => $rule->docsUrl(),
                ];
            }
            $output->writeln(json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

            return Command::SUCCESS;
        }

        foreach ($rules as $rule) {
            $output->writeln(sprintf(
                ' <info>%s</info>  [%s] %s (%s)',
                $rule->value,
                str_pad($rule->defaultSeverity()->value, 7),
                $rule->title(),
                $rule->phpstanIdentifier()
            ));
        }

        return Command::SUCCESS;
    }
}
