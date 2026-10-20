<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\AstHelper;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\ExternalCallMatcher;
use Malikad\QueueGuard\Support\TransactionScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

final class HttpInsideTransactionDetector implements Detector
{
    public function __construct(
        private readonly TransactionScopeFinder $scopeFinder = new TransactionScopeFinder,
        private readonly ExternalCallMatcher $callMatcher = new ExternalCallMatcher,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG001;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];
        $classMap = $this->indexClassMethods($ast);

        $scopes = $this->scopeFinder->findScopes($ast);

        foreach ($scopes as $scope) {
            $ancestors = [];
            if ($scope['parentNode'] !== null) {
                $ancestors[] = $scope['parentNode'];
            }
            if ($scope['class'] !== null) {
                $ancestors[] = $scope['class'];
            }

            $this->scanScopeStatements(
                $scope['stmts'],
                $ancestors,
                $classMap,
                0,
                $config->maxCallDepth,
                $filePath,
                $config,
                $findings
            );
        }

        return $findings;
    }

    /**
     * @param  list<Node>  $stmts
     * @param  list<Node>  $ancestors
     * @param  array<int, array<string, ClassMethod>>  $classMap
     * @param  list<Finding>  $findings
     */
    private function scanScopeStatements(
        array $stmts,
        array $ancestors,
        array $classMap,
        int $depth,
        int $maxDepth,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        foreach ($stmts as $stmt) {
            $this->scanNode($stmt, $ancestors, $classMap, $depth, $maxDepth, $filePath, $config, $findings);
        }
    }

    /**
     * @param  list<Node>  $ancestors
     * @param  array<int, array<string, ClassMethod>>  $classMap
     * @param  list<Finding>  $findings
     */
    private function scanNode(
        Node $node,
        array $ancestors,
        array $classMap,
        int $depth,
        int $maxDepth,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        if ($this->scopeFinder->isAfterCommitCall($node)) {
            return;
        }

        if ($this->docblockInspector->isExempt($node, RuleId::QG001, $ancestors)) {
            return;
        }

        $enclosingClass = $this->findEnclosingClass($ancestors);
        $matchedCall = $this->callMatcher->matchExternalCall($node, $config->externalCallClasses, $enclosingClass);
        if ($matchedCall !== null) {
            $findings[] = new Finding(
                ruleId: RuleId::QG001,
                severity: $config->severityFor(RuleId::QG001),
                file: $filePath,
                line: $node->getStartLine(),
                message: "External call {$matchedCall} inside a database transaction holds row locks during network I/O.",
                tip: 'Move the call after commit or wrap it in DB::afterCommit().',
            );

            return;
        }

        // Follow bounded $this->helperMethod() calls inside the enclosing class
        if (
            $depth < $maxDepth
            && $node instanceof MethodCall
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $targetName = $node->name->toString();
            $enclosingClass = $this->findEnclosingClass($ancestors);
            $classMethods = $enclosingClass !== null ? ($classMap[spl_object_id($enclosingClass)] ?? []) : [];

            if (! isset($classMethods[$targetName])) {
                foreach ($classMap as $methods) {
                    if (isset($methods[$targetName])) {
                        $classMethods = $methods;
                        break;
                    }
                }
            }

            if (isset($classMethods[$targetName])) {
                $targetMethod = $classMethods[$targetName];
                $targetStmts = $targetMethod->stmts;
                if ($targetStmts !== null) {
                    $this->scanScopeStatements(
                        array_values($targetStmts),
                        array_values(array_merge($ancestors, [$node, $targetMethod])),
                        $classMap,
                        $depth + 1,
                        $maxDepth,
                        $filePath,
                        $config,
                        $findings
                    );
                }
            }
        }

        $nextAncestors = array_values(array_merge($ancestors, [$node]));
        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->$subName;
            if ($sub instanceof Node) {
                $this->scanNode($sub, $nextAncestors, $classMap, $depth, $maxDepth, $filePath, $config, $findings);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanNode($item, $nextAncestors, $classMap, $depth, $maxDepth, $filePath, $config, $findings);
                    }
                }
            }
        }
    }

    /**
     * @param  array<Node>  $ast
     * @return array<int, array<string, ClassMethod>>
     */
    private function indexClassMethods(array $ast): array
    {
        $map = [];
        AstHelper::walkClasses($ast, function (Class_ $class) use (&$map): void {
            $methods = [];
            foreach ($class->getMethods() as $method) {
                $methods[$method->name->toString()] = $method;
            }
            $map[spl_object_id($class)] = $methods;
        });

        return $map;
    }

    /**
     * @param  list<Node>  $ancestors
     */
    private function findEnclosingClass(array $ancestors): ?Class_
    {
        for ($i = count($ancestors) - 1; $i >= 0; $i--) {
            if ($ancestors[$i] instanceof Class_) {
                return $ancestors[$i];
            }
        }

        return null;
    }
}
