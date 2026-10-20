<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DispatchCallMatcher;
use Malikad\QueueGuard\Support\DocblockInspector;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;

final class SyncDispatchInLoopDetector implements Detector
{
    private const COLLECTION_LOOP_METHODS = [
        'each', 'map', 'chunk', 'chunkById', 'lazy', 'lazyById',
    ];

    public function __construct(
        private readonly DispatchCallMatcher $dispatchMatcher = new DispatchCallMatcher,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG006;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        $this->walkWithAncestors($ast, [], function (Node $node, array $ancestors) use (&$findings, $filePath, $config): void {
            if ($this->isLoopConstruct($node)) {
                $loopBody = $this->extractLoopBody($node);
                if ($loopBody !== []) {
                    $this->scanLoopBody($loopBody, array_values(array_merge($ancestors, [$node])), $filePath, $config, $findings);
                }
            }
        });

        return $findings;
    }

    private function isLoopConstruct(Node $node): bool
    {
        if ($node instanceof Foreach_) {
            // Skip tiny fixed array literals (<= 3 items)
            if ($node->expr instanceof Array_ && count($node->expr->items) <= 3) {
                return false;
            }

            return true;
        }

        if ($node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            return true;
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            if (in_array($node->name->toString(), self::COLLECTION_LOOP_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Node>
     */
    private function extractLoopBody(Node $node): array
    {
        if ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_ || $node instanceof Do_) {
            return array_values($node->stmts);
        }

        if ($node instanceof MethodCall) {
            // Look through all arguments (e.g. chunk($size, $callback) has callback at index 1)
            foreach ($node->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    if ($arg->value instanceof Closure) {
                        return array_values($arg->value->stmts);
                    }
                    if ($arg->value instanceof ArrowFunction) {
                        return [$arg->value->expr];
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param  list<Node>  $stmts
     * @param  list<Node>  $ancestors
     * @param  list<Finding>  $findings
     */
    private function scanLoopBody(
        array $stmts,
        array $ancestors,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        foreach ($stmts as $stmt) {
            $this->scanNode($stmt, $ancestors, $filePath, $config, $findings);
        }
    }

    /**
     * @param  list<Node>  $ancestors
     * @param  list<Finding>  $findings
     */
    private function scanNode(
        Node $node,
        array $ancestors,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        if ($this->docblockInspector->isExempt($node, RuleId::QG006, $ancestors)) {
            return;
        }

        // 1. Check synchronous dispatch (e.g. dispatchSync)
        $syncMatched = $this->dispatchMatcher->matchSyncDispatchCall($node);
        if ($syncMatched !== null) {
            $findings[] = new Finding(
                ruleId: RuleId::QG006,
                severity: $config->severityFor(RuleId::QG006),
                file: $filePath,
                line: $node->getStartLine(),
                message: "Synchronous dispatch ({$syncMatched}) inside a loop blocks thread execution sequentially on every iteration.",
                tip: 'Queue jobs asynchronously with dispatch(), or use Bus::batch() for bulk jobs.',
            );

            return;
        }

        // 2. Check async queue dispatch (e.g. dispatch)
        $matched = $this->dispatchMatcher->matchDispatchCall($node);
        if ($matched !== null) {
            $findings[] = new Finding(
                ruleId: RuleId::QG006,
                severity: $config->severityFor(RuleId::QG006),
                file: $filePath,
                line: $node->getStartLine(),
                message: "Individual queue or notification dispatch ({$matched}) inside a loop causes N network round-trips.",
                tip: 'Use Bus::batch(), Notification::send($users, ...), or bulk queueing outside the loop.',
            );

            return;
        }

        $nextAncestors = array_merge($ancestors, [$node]);
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof Node && ! $this->isLoopConstruct($sub)) {
                $this->scanNode($sub, $nextAncestors, $filePath, $config, $findings);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node && ! $this->isLoopConstruct($item)) {
                        $this->scanNode($item, $nextAncestors, $filePath, $config, $findings);
                    }
                }
            }
        }
    }

    /**
     * @param  array<Node>  $nodes
     * @param  list<Node>  $ancestors
     * @param  callable(Node, list<Node>): void  $callback
     */
    private function walkWithAncestors(array $nodes, array $ancestors, callable $callback): void
    {
        foreach ($nodes as $node) {
            $callback($node, $ancestors);
            $nextAncestors = array_values(array_merge($ancestors, [$node]));
            foreach ($node->getSubNodeNames() as $name) {
                $sub = $node->$name;
                if ($sub instanceof Node) {
                    $this->walkWithAncestors([$sub], $nextAncestors, $callback);
                } elseif (is_array($sub)) {
                    $children = array_values(array_filter($sub, static fn ($item): bool => $item instanceof Node));
                    $this->walkWithAncestors($children, $nextAncestors, $callback);
                }
            }
        }
    }
}
