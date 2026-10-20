<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class DispatchCallMatcher
{
    /**
     * Matches asynchronous job/notification/mail dispatch calls.
     * Synchronous execution methods (dispatchSync, dispatch_sync, notifyNow, Mail::send) are excluded
     * as they run in-process and do not risk premature queue worker execution.
     */
    public function matchDispatchCall(Node $node): ?string
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = ltrim($node->name->toString(), '\\');
            if ($fn === 'dispatch') {
                return 'dispatch()';
            }
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $className = ltrim($node->class->toString(), '\\');
            $method = $node->name->toString();

            // Match Job::dispatch(), Bus::dispatch(), etc. Exclude dispatchSync, dispatchNow
            if (in_array($method, ['dispatch', 'dispatchIf', 'dispatchUnless'], true)) {
                return "{$className}::{$method}()";
            }

            if (
                ($className === 'Notification' || str_ends_with($className, '\\Notification'))
                && $method === 'send'
            ) {
                return 'Notification::send()';
            }

            if (
                ($className === 'Mail' || str_ends_with($className, '\\Mail'))
                && $method === 'queue'
            ) {
                return 'Mail::queue()';
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();
            if ($method === 'notify') {
                return '->notify()';
            }

            if ($method === 'queue' && $this->isMailToChain($node->var)) {
                return 'Mail::to()->queue()';
            }
        }

        return null;
    }

    /**
     * Matches synchronous job execution calls (e.g. dispatchSync, dispatchNow).
     */
    public function matchSyncDispatchCall(Node $node): ?string
    {
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = ltrim($node->name->toString(), '\\');
            if (in_array($fn, ['dispatch_sync', 'dispatch_now'], true)) {
                return "{$fn}()";
            }
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $className = ltrim($node->class->toString(), '\\');
            $method = $node->name->toString();

            if (in_array($method, ['dispatchSync', 'dispatchNow'], true)) {
                return "{$className}::{$method}()";
            }
        }

        return null;
    }

    /**
     * Checks if this specific dispatch call expression has ->afterCommit() chained on it.
     * Only inspects the chain directly attached to this dispatch call, NOT surrounding statements.
     */
    public function hasAfterCommitChain(Node $callNode): bool
    {
        // If the call is wrapped directly: e.g. Job::dispatch()->afterCommit()
        if ($callNode instanceof MethodCall && $callNode->name instanceof Identifier && $callNode->name->toString() === 'afterCommit') {
            return true;
        }

        return false;
    }

    private function isMailToChain(Node\Expr $expr): bool
    {
        if ($expr instanceof StaticCall && $expr->class instanceof Name) {
            $className = ltrim($expr->class->toString(), '\\');

            return $className === 'Mail' || str_ends_with($className, '\\Mail');
        }

        if ($expr instanceof MethodCall) {
            return $this->isMailToChain($expr->var);
        }

        return false;
    }
}
