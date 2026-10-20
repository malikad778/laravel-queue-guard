<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DocblockInspector;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class BatchWithoutFinallyDetector implements Detector
{
    private const DISPATCH_METHODS = ['dispatch', 'dispatchAfterResponse'];

    public function __construct(
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG005;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        // Inspect statements across the entire AST (top-level and nested callable bodies)
        $this->inspectStatementList($ast, [], $filePath, $config, $findings);

        return $findings;
    }

    /**
     * @param  array<Node>  $stmts
     * @param  list<Node>  $ancestors
     * @param  list<Finding>  $findings
     */
    private function inspectStatementList(
        array $stmts,
        array $ancestors,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        /** @var array<string, array{type: string, hasHandler: bool}> $batchVars */
        $batchVars = [];

        $this->walkWithAncestors($stmts, $ancestors, function (Node $node, array $nodeAncestors) use (&$batchVars, &$findings, $filePath, $config): void {
            if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                $varName = $node->var->name;
                $chainInfo = $this->analyzeChain($node->expr, $batchVars);
                if ($chainInfo !== null) {
                    $batchVars[$varName] = [
                        'type' => $chainInfo['type'],
                        'hasHandler' => $chainInfo['hasHandler'],
                    ];
                }
            }

            if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                $methodName = $node->name->toString();
                if (in_array($methodName, ['finally', 'catch'], true) && $node->var instanceof Variable && is_string($node->var->name)) {
                    $varName = $node->var->name;
                    if (isset($batchVars[$varName])) {
                        $batchVars[$varName]['hasHandler'] = true;
                    }
                }

                if (in_array($methodName, self::DISPATCH_METHODS, true)) {
                    if ($this->docblockInspector->isExempt($node, RuleId::QG005, $nodeAncestors)) {
                        return;
                    }

                    $chainInfo = $this->analyzeChain($node, $batchVars);
                    if ($chainInfo !== null && ! $chainInfo['hasHandler']) {
                        $label = $chainInfo['type'] === 'chain' ? 'Bus::chain()' : 'Bus::batch()';
                        $findings[] = new Finding(
                            ruleId: RuleId::QG005,
                            severity: $config->severityFor(RuleId::QG005),
                            file: $filePath,
                            line: $node->getStartLine(),
                            message: "{$label} dispatched without a finally() or catch() callback to handle failure or cleanup.",
                            tip: 'Chain ->finally(function (Batch $batch) { ... }) or ->catch(...) before calling ->dispatch().',
                        );
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, array{type: string, hasHandler: bool}>  $batchVars
     * @return array{type: string, hasHandler: bool}|null
     */
    private function analyzeChain(Node\Expr $expr, array $batchVars): ?array
    {
        $hasHandler = false;
        $current = $expr;

        while ($current instanceof MethodCall) {
            if ($current->name instanceof Identifier) {
                $name = $current->name->toString();
                if (in_array($name, ['finally', 'catch'], true)) {
                    $hasHandler = true;
                }
            }
            $current = $current->var;
        }

        if ($current instanceof StaticCall && $current->class instanceof Name && $current->name instanceof Identifier) {
            $className = ltrim($current->class->toString(), '\\');
            $method = $current->name->toString();
            if (($className === 'Bus' || str_ends_with($className, '\\Bus')) && in_array($method, ['batch', 'chain'], true)) {
                return [
                    'type' => $method,
                    'hasHandler' => $hasHandler,
                ];
            }
        }

        if ($current instanceof Variable && is_string($current->name) && isset($batchVars[$current->name])) {
            return [
                'type' => $batchVars[$current->name]['type'],
                'hasHandler' => $batchVars[$current->name]['hasHandler'] || $hasHandler,
            ];
        }

        return null;
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
                    $childNodes = array_values(array_filter($sub, static fn ($item): bool => $item instanceof Node));
                    $this->walkWithAncestors($childNodes, $nextAncestors, $callback);
                }
            }
        }
    }
}
