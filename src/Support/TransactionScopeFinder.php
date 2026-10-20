<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\TryCatch;

final class TransactionScopeFinder
{
    /**
     * Finds all statement lists that execute inside a DB transaction (excluding statements inside DB::afterCommit).
     *
     * @param  array<Node>  $stmts
     * @return list<array{stmts: list<Node>, startLine: int, parentNode: ?Node, class: ?Class_}>
     */
    public function findScopes(array $stmts): array
    {
        $scopes = [];
        $seenClosures = [];

        // 1. Collect all transaction closures (deduplicated)
        $this->collectTransactionClosures($stmts, $scopes, $seenClosures);

        // 2. Collect explicit beginTransaction() ... commit() spans in all statement blocks
        $this->collectExplicitSpans($stmts, $scopes);

        return $scopes;
    }

    public function isTransactionClosureCall(Node $node): bool
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            if ($node->name->toString() === 'transaction' && $node->class instanceof Name) {
                $className = ltrim($node->class->toString(), '\\');
                if ($className === 'DB' || str_ends_with($className, '\\DB')) {
                    return true;
                }
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            if ($node->name->toString() === 'transaction') {
                return true;
            }
        }

        return false;
    }

    public function isAfterCommitCall(Node $node): bool
    {
        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            if ($node->name->toString() === 'afterCommit' && $node->class instanceof Name) {
                $className = ltrim($node->class->toString(), '\\');

                return $className === 'DB' || str_ends_with($className, '\\DB');
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            return $node->name->toString() === 'afterCommit';
        }

        return false;
    }

    /**
     * @param  array<Node>  $nodes
     * @param  list<array{stmts: list<Node>, startLine: int, parentNode: ?Node, class: ?Class_}>  $scopes
     * @param  array<int, bool>  $seenClosures
     */
    private function collectTransactionClosures(
        array $nodes,
        array &$scopes,
        array &$seenClosures,
        ?Node $enclosingStmt = null,
        ?Class_ $enclosingClass = null
    ): void {
        foreach ($nodes as $node) {
            $currentStmt = ($node instanceof Stmt) ? $node : $enclosingStmt;
            $currentClass = ($node instanceof Class_) ? $node : $enclosingClass;

            if ($this->isTransactionClosureCall($node) && ($node instanceof StaticCall || $node instanceof MethodCall)) {
                if (isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                    $callback = $node->args[0]->value;
                    $objId = spl_object_id($callback);

                    if (! isset($seenClosures[$objId])) {
                        $seenClosures[$objId] = true;

                        if ($callback instanceof Closure) {
                            $scopes[] = [
                                'stmts' => array_values($callback->stmts),
                                'startLine' => $node->getStartLine(),
                                'parentNode' => $currentStmt ?? $node,
                                'class' => $currentClass,
                            ];
                        } elseif ($callback instanceof ArrowFunction) {
                            $scopes[] = [
                                'stmts' => [$callback->expr],
                                'startLine' => $node->getStartLine(),
                                'parentNode' => $currentStmt ?? $node,
                                'class' => $currentClass,
                            ];
                        }
                    }
                }
            }

            // Recurse into subnodes, without entering afterCommit calls
            if (! $this->isAfterCommitCall($node)) {
                foreach ($node->getSubNodeNames() as $subName) {
                    /** @var mixed $sub */
                    $sub = $node->$subName;
                    if ($sub instanceof Node) {
                        $this->collectTransactionClosures([$sub], $scopes, $seenClosures, $currentStmt, $currentClass);
                    } elseif (is_array($sub)) {
                        $childNodes = [];
                        foreach ($sub as $item) {
                            if ($item instanceof Node) {
                                $childNodes[] = $item;
                            }
                        }
                        if ($childNodes !== []) {
                            $this->collectTransactionClosures($childNodes, $scopes, $seenClosures, $currentStmt, $currentClass);
                        }
                    }
                }
            }
        }
    }

    /**
     * Finds statement spans between beginTransaction() and commit() / rollBack()
     * inside any statement block.
     *
     * @param  array<Node>  $nodes
     * @param  list<array{stmts: list<Node>, startLine: int, parentNode: ?Node, class: ?Class_}>  $scopes
     */
    private function collectExplicitSpans(array $nodes, array &$scopes, ?Class_ $enclosingClass = null): void
    {
        $inTx = false;
        $buffer = [];
        $startLine = 0;

        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            $stmt = $nodes[$i];
            $currentClass = ($stmt instanceof Class_) ? $stmt : $enclosingClass;

            if ($this->isBeginTransactionStatement($stmt)) {
                $inTx = true;
                $buffer = [];
                $startLine = $stmt->getStartLine();

                if ($i + 1 < $count && $nodes[$i + 1] instanceof TryCatch) {
                    $tryCatch = $nodes[$i + 1];
                    $tryBuffer = [];

                    foreach ($tryCatch->stmts as $tryStmt) {
                        if ($this->isCommitOrRollbackStatement($tryStmt)) {
                            break;
                        }
                        $tryBuffer[] = $tryStmt;
                    }

                    if ($tryBuffer !== []) {
                        $scopes[] = [
                            'stmts' => $tryBuffer,
                            'startLine' => $startLine,
                            'parentNode' => $tryCatch,
                            'class' => $currentClass,
                        ];
                    }

                    $inTx = false;
                    $buffer = [];
                    $i++;

                    continue;
                }

                continue;
            }

            if ($this->isCommitOrRollbackStatement($stmt)) {
                if ($inTx && $buffer !== []) {
                    $scopes[] = [
                        'stmts' => $buffer,
                        'startLine' => $startLine,
                        'parentNode' => null,
                        'class' => $currentClass,
                    ];
                }
                $inTx = false;
                $buffer = [];

                continue;
            }

            if ($inTx) {
                $buffer[] = $stmt;
            }

            if ($stmt instanceof Class_) {
                $this->collectExplicitSpans($stmt->stmts, $scopes, $stmt);
            } elseif ($stmt instanceof ClassMethod || $stmt instanceof Function_) {
                if ($stmt->stmts !== null) {
                    $this->collectExplicitSpans($stmt->stmts, $scopes, $currentClass);
                }
            } elseif ($stmt instanceof TryCatch) {
                $this->collectExplicitSpans($stmt->stmts, $scopes, $currentClass);
                foreach ($stmt->catches as $catch) {
                    $this->collectExplicitSpans($catch->stmts, $scopes, $currentClass);
                }
                if ($stmt->finally !== null) {
                    $this->collectExplicitSpans($stmt->finally->stmts, $scopes, $currentClass);
                }
            } elseif (
                $stmt instanceof Stmt\Foreach_
                || $stmt instanceof Stmt\For_
                || $stmt instanceof Stmt\While_
                || $stmt instanceof Stmt\Do_
            ) {
                $this->collectExplicitSpans($stmt->stmts, $scopes, $currentClass);
            } elseif ($stmt instanceof Stmt\If_) {
                $this->collectExplicitSpans($stmt->stmts, $scopes, $currentClass);
                foreach ($stmt->elseifs as $elseif) {
                    $this->collectExplicitSpans($elseif->stmts, $scopes, $currentClass);
                }
                if ($stmt->else !== null) {
                    $this->collectExplicitSpans($stmt->else->stmts, $scopes, $currentClass);
                }
            } elseif ($stmt instanceof Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    $this->collectExplicitSpans($case->stmts, $scopes, $currentClass);
                }
            }
        }
    }

    private function isBeginTransactionStatement(Node $stmt): bool
    {
        if ($stmt instanceof Expression && ($stmt->expr instanceof StaticCall || $stmt->expr instanceof MethodCall)) {
            $call = $stmt->expr;
            if ($call->name instanceof Identifier && $call->name->toString() === 'beginTransaction') {
                return true;
            }
        }

        return false;
    }

    private function isCommitOrRollbackStatement(Node $stmt): bool
    {
        if ($stmt instanceof Expression && ($stmt->expr instanceof StaticCall || $stmt->expr instanceof MethodCall)) {
            $call = $stmt->expr;
            if ($call->name instanceof Identifier) {
                $name = $call->name->toString();

                return $name === 'commit' || $name === 'rollBack' || $name === 'rollback';
            }
        }

        return false;
    }
}
