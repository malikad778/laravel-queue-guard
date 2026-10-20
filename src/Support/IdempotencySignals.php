<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_ as ThrowExpr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;

final class IdempotencySignals
{
    public const NONE = 'none';

    public const UNIQUE_LOCK_ONLY = 'unique_lock_only';

    public const STRONG = 'strong';

    /**
     * Determines whether a financial call is protected by strong idempotency, unique lock only, or none.
     *
     * @param  list<Node>  $ancestors
     */
    public function evaluateCall(
        Class_ $class,
        ClassMethod $handleMethod,
        Node $callNode,
        array $ancestors,
        bool $hasPrecedingGuard
    ): string {
        // 1. If the class or any helper method builds/passes an idempotency key
        if ($this->classHasIdempotencyKey($class)) {
            return self::STRONG;
        }

        // 2. If a state guard or early return was executed prior to this statement
        if ($hasPrecedingGuard) {
            return self::STRONG;
        }

        // 3. If this call is enclosed within a conditional guard (e.g. if ($order->status === 'pending'))
        if ($this->isInsideGuardedBlock($ancestors)) {
            return self::STRONG;
        }

        // 4. If the job only implements ShouldBeUnique or uses WithoutOverlapping
        if ($this->hasUniqueOrWithoutOverlapping($class)) {
            return self::UNIQUE_LOCK_ONLY;
        }

        return self::NONE;
    }

    /**
     * Checks if the class, handle method, or any helper methods construct an idempotency key.
     */
    public function classHasIdempotencyKey(Class_ $class): bool
    {
        $hasKey = false;
        AstHelper::walkWithAncestors([$class], function (Node $node) use (&$hasKey): void {
            if ($node instanceof ArrayItem && $node->key instanceof String_) {
                $key = strtolower($node->key->value);
                if (in_array($key, ['idempotency_key', 'idempotency-key', 'x-idempotency-key', 'idempotencykey'], true)) {
                    $hasKey = true;
                }
            }

            if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                $name = strtolower($node->name->toString());
                if (in_array($name, ['withidempotencykey', 'idempotencykey'], true)) {
                    $hasKey = true;
                }
            }
        });

        return $hasKey;
    }

    /**
     * Checks if a statement acts as a preceding guard (e.g. early return if already paid, or lockForUpdate).
     */
    public function isPrecedingGuardStatement(Node\Stmt $stmt): bool
    {
        // 1. Early return / throw on status or attempts guard
        // e.g. if ($order->status === 'paid' || $order->isPaid() || $this->attempts() > 1) { return; }
        if ($stmt instanceof If_) {
            $hasTerminal = false;
            foreach ($stmt->stmts as $innerStmt) {
                if ($innerStmt instanceof Return_ || ($innerStmt instanceof Expression && $innerStmt->expr instanceof ThrowExpr)) {
                    $hasTerminal = true;
                    break;
                }
            }

            if ($hasTerminal && ($this->nodeContainsStatusCheck($stmt->cond) || $this->isAttemptsCondition($stmt->cond))) {
                return true;
            }
        }

        // 2. Database lock or find-or-create executed in a statement before the call
        // e.g. $order = Order::where(...)->lockForUpdate()->first();
        // e.g. Order::firstOrCreate(...)
        $hasLock = false;
        AstHelper::walkWithAncestors([$stmt], function (Node $node) use (&$hasLock): void {
            if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                $name = $node->name->toString();
                if (in_array($name, ['lockForUpdate', 'sharedLock', 'firstOrCreate', 'updateOrCreate'], true)) {
                    $hasLock = true;
                }
            }
            if ($node instanceof StaticCall && $node->name instanceof Identifier) {
                $name = $node->name->toString();
                if (in_array($name, ['firstOrCreate', 'updateOrCreate'], true)) {
                    $hasLock = true;
                }
            }
        });

        return $hasLock;
    }

    /**
     * Checks if any enclosing ancestor node provides a status or attempts guard.
     *
     * @param  list<Node>  $ancestors
     */
    public function isInsideGuardedBlock(array $ancestors): bool
    {
        foreach ($ancestors as $ancestor) {
            if ($ancestor instanceof If_) {
                if ($this->nodeContainsStatusCheck($ancestor->cond) || $this->isAttemptsCondition($ancestor->cond)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Checks whether an expression is a conditional check on attempts(), e.g. $this->attempts() <= 1.
     * Note: strictly requires comparison/conditional context, so Log::info($this->attempts()) is ignored.
     */
    public function isAttemptsCondition(Node $expr): bool
    {
        $hasAttemptsComparison = false;
        AstHelper::walkWithAncestors([$expr], function (Node $node) use (&$hasAttemptsComparison): void {
            if ($node instanceof BinaryOp) {
                $leftHas = $this->hasAttemptsCall($node->left);
                $rightHas = $this->hasAttemptsCall($node->right);
                if ($leftHas || $rightHas) {
                    $hasAttemptsComparison = true;
                }
            }
        });

        return $hasAttemptsComparison;
    }

    private function hasAttemptsCall(Node $node): bool
    {
        $found = false;
        AstHelper::walkWithAncestors([$node], function (Node $n) use (&$found): void {
            if ($n instanceof MethodCall && $n->name instanceof Identifier && $n->name->toString() === 'attempts') {
                $found = true;
            }
        });

        return $found;
    }

    public function nodeContainsStatusCheck(Node $cond): bool
    {
        $found = false;
        AstHelper::walkWithAncestors([$cond], function (Node $n) use (&$found): void {
            if ($n instanceof PropertyFetch && $n->name instanceof Identifier) {
                $prop = strtolower($n->name->toString());
                if (in_array($prop, ['status', 'paid_at', 'processed_at', 'charged_at', 'state', 'is_paid'], true)) {
                    $found = true;
                }
            }

            if ($n instanceof MethodCall && $n->name instanceof Identifier) {
                $mName = strtolower($n->name->toString());
                if (in_array($mName, ['ispaid', 'isprocessed', 'alreadyprocessed', 'hasbeenpaid', 'iscompleted'], true)) {
                    $found = true;
                }
            }
        });

        return $found;
    }

    public function hasUniqueOrWithoutOverlapping(Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            $name = ltrim($interface->toString(), '\\');
            if (
                $name === 'ShouldBeUnique'
                || $name === 'ShouldBeUniqueUntilProcessing'
                || str_ends_with($name, '\\ShouldBeUnique')
                || str_ends_with($name, '\\ShouldBeUniqueUntilProcessing')
            ) {
                return true;
            }
        }

        $middlewareMethod = $class->getMethod('middleware');
        if ($middlewareMethod !== null) {
            $hasOverlap = false;
            AstHelper::walkWithAncestors([$middlewareMethod], function (Node $node) use (&$hasOverlap): void {
                if ($node instanceof New_ && $node->class instanceof Name) {
                    $cls = ltrim($node->class->toString(), '\\');
                    if ($cls === 'WithoutOverlapping' || str_ends_with($cls, '\\WithoutOverlapping')) {
                        $hasOverlap = true;
                    }
                }
            });
            if ($hasOverlap) {
                return true;
            }
        }

        return false;
    }
}
