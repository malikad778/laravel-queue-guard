<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\PHPStan\Rules;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FileNode>
 */
abstract class AbstractQueueGuardRule implements Rule
{
    protected readonly Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config(projectRoot: getcwd() ?: '');
    }

    abstract protected function detector(): Detector;

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof FileNode) {
            return [];
        }

        if (! $this->config->isRuleEnabled($this->detector()->ruleId())) {
            return [];
        }

        /** @var array<Node\Stmt> $stmts */
        $stmts = $node->getNodes();
        $findings = $this->detector()->detect($stmts, $scope->getFile(), $this->config);

        $errors = [];
        foreach ($findings as $finding) {
            $errors[] = RuleErrorBuilder::message($finding->message)
                ->line($finding->line)
                ->identifier($finding->ruleId->phpstanIdentifier())
                ->tip($finding->tip)
                ->build();
        }

        return $errors;
    }
}
