<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\AstHelper;
use Malikad\QueueGuard\Support\DispatchCallMatcher;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\JobConfigReader;
use Malikad\QueueGuard\Support\StaticConfigReader;
use Malikad\QueueGuard\Support\TransactionScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;

final class DispatchInsideTransactionDetector implements Detector
{
    public function __construct(
        private readonly TransactionScopeFinder $scopeFinder = new TransactionScopeFinder,
        private readonly DispatchCallMatcher $dispatchMatcher = new DispatchCallMatcher,
        private readonly JobConfigReader $jobConfigReader = new JobConfigReader,
        private readonly StaticConfigReader $staticConfigReader = new StaticConfigReader,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG007;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        // If the default queue connection has after_commit => true configured in config/queue.php
        if ($config->projectRoot !== '' && $this->staticConfigReader->isConnectionAfterCommit($config->projectRoot)) {
            return [];
        }

        $afterCommitClasses = $this->indexAfterCommitClasses($ast);
        $scopes = $this->scopeFinder->findScopes($ast);

        foreach ($scopes as $scope) {
            $ancestors = [];
            if ($scope['parentNode'] !== null) {
                $ancestors[] = $scope['parentNode'];
            }

            foreach ($scope['stmts'] as $stmt) {
                $this->scanStatement(
                    $stmt,
                    $ancestors,
                    $afterCommitClasses,
                    $filePath,
                    $config,
                    $findings
                );
            }
        }

        return $findings;
    }

    /**
     * @param  list<Node>  $ancestors
     * @param  array<string, bool>  $afterCommitClasses
     * @param  list<Finding>  $findings
     */
    private function scanStatement(
        Node $node,
        array $ancestors,
        array $afterCommitClasses,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        if ($this->scopeFinder->isAfterCommitCall($node)) {
            return;
        }

        if ($this->dispatchMatcher->hasAfterCommitChain($node)) {
            return;
        }

        if ($this->docblockInspector->isExempt($node, RuleId::QG007, $ancestors)) {
            return;
        }

        $matched = $this->dispatchMatcher->matchDispatchCall($node);
        if ($matched !== null) {
            // Check if dispatched job class itself implements ShouldQueueAfterCommit or has $afterCommit = true
            if ($node instanceof StaticCall && $node->class instanceof Node\Name) {
                $cls = ltrim($node->class->toString(), '\\');
                if (isset($afterCommitClasses[$cls]) || str_ends_with($cls, 'AfterCommit')) {
                    return;
                }
            }

            $findings[] = new Finding(
                ruleId: RuleId::QG007,
                severity: $config->severityFor(RuleId::QG007),
                file: $filePath,
                line: $node->getStartLine(),
                message: "Queue dispatch {$matched} inside a database transaction without ->afterCommit() may execute before the transaction commits.",
                tip: 'Chain ->afterCommit() on the dispatch call, implement ShouldQueueAfterCommit, or wrap in DB::afterCommit().',
            );

            return;
        }

        $nextAncestors = array_merge($ancestors, [$node]);
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof Node) {
                $this->scanStatement($sub, $nextAncestors, $afterCommitClasses, $filePath, $config, $findings);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanStatement($item, $nextAncestors, $afterCommitClasses, $filePath, $config, $findings);
                    }
                }
            }
        }
    }

    /**
     * @param  array<Node>  $ast
     * @return array<string, bool>
     */
    private function indexAfterCommitClasses(array $ast): array
    {
        $classes = [];
        AstHelper::walkClasses($ast, function (Class_ $class) use (&$classes): void {
            if ($this->jobConfigReader->hasAfterCommitProperty($class)) {
                if ($class->name !== null) {
                    $classes[$class->name->toString()] = true;
                    if (isset($class->namespacedName)) {
                        $classes[ltrim($class->namespacedName->toString(), '\\')] = true;
                    }
                }
            }
        });

        return $classes;
    }
}
