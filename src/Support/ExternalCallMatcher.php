<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

final class ExternalCallMatcher
{
    private const HTTP_METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'head', 'send',
        'request', 'requestAsync', 'getAsync', 'postAsync',
    ];

    private const STRIPE_RESOURCES = [
        'charges', 'paymentintents', 'refunds', 'transfers', 'payouts',
        'customers', 'subscriptions', 'invoices', 'paymentmethods',
    ];

    /**
     * Checks if a node represents an external network/HTTP/SDK call.
     *
     * @param  list<string>  $extraClasses
     */
    public function matchExternalCall(Node $node, array $extraClasses = [], ?Node\Stmt\Class_ $enclosingClass = null): ?string
    {
        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $className = ltrim($node->class->toString(), '\\');
            $method = $node->name->toString();

            if (
                ($className === 'Http' || str_ends_with($className, '\\Http'))
                && in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'send', 'pool'], true)
            ) {
                return "Http::{$method}()";
            }

            if (str_starts_with($className, 'Stripe\\')) {
                return "{$className}::{$method}()";
            }

            if (
                ($className === 'Mail' || str_ends_with($className, '\\Mail'))
                && in_array($method, ['send', 'raw', 'html', 'plain'], true)
            ) {
                return "Mail::{$method}()";
            }

            if (
                ($className === 'Notification' || str_ends_with($className, '\\Notification'))
                && $method === 'sendNow'
            ) {
                return 'Notification::sendNow()';
            }

            foreach ($extraClasses as $extra) {
                $cleanExtra = ltrim($extra, '\\');
                if ($className === $cleanExtra || str_ends_with($className, '\\'.$cleanExtra)) {
                    return "{$className}::{$method}()";
                }
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();

            // Modern Stripe SDK: $stripe->paymentIntents->create(...) or $this->stripe->charges->create(...)
            if ($this->isStripeResourceCall($node)) {
                return "Stripe::{$method}()";
            }

            // Chained Http::withHeaders(...)->post(...) or Guzzle Client->request(...)
            if (in_array($method, self::HTTP_METHODS, true)) {
                if ($this->isHttpCallChain($node->var)) {
                    return "Http::{$method}()";
                }
            }

            // Mail::to(...)->send(...)
            if ($method === 'send' && $this->isMailToChain($node->var)) {
                return 'Mail::send()';
            }

            // Check injected service instances: $this->gateway->pay() or $this->gw->charge()
            if ($enclosingClass !== null && $this->isMatchedInjectedProperty($node->var, $enclosingClass, $extraClasses)) {
                return "->{$method}()";
            }
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = strtolower(ltrim($node->name->toString(), '\\'));
            if ($fn === 'curl_exec' || $fn === 'curl_multi_exec') {
                return "{$fn}()";
            }

            if ($fn === 'file_get_contents' && isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                $firstArg = $node->args[0]->value;
                if ($firstArg instanceof String_ && preg_match('#^https?://#i', $firstArg->value) === 1) {
                    return 'file_get_contents(URL)';
                }
            }
        }

        return null;
    }

    /**
     * Checks if a node represents a financial or irreversible side-effect call.
     *
     * @param  list<string>  $extraFinancialClasses
     */
    public function matchFinancialCall(Node $node, array $extraFinancialClasses = [], ?Node\Stmt\Class_ $enclosingClass = null): ?string
    {
        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $className = ltrim($node->class->toString(), '\\');
            $method = $node->name->toString();

            if (str_starts_with($className, 'Stripe\\')) {
                if (in_array($method, ['create', 'capture', 'refund', 'charge', 'update'], true)) {
                    return "{$className}::{$method}()";
                }
            }

            if (
                ($className === 'Http' || str_ends_with($className, '\\Http'))
                && in_array($method, ['post', 'put', 'patch', 'delete'], true)
            ) {
                return "Http::{$method}()";
            }

            foreach ($extraFinancialClasses as $extra) {
                $cleanExtra = ltrim($extra, '\\');
                if ($className === $cleanExtra || str_ends_with($className, '\\'.$cleanExtra)) {
                    return "{$className}::{$method}()";
                }
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();

            // Modern Stripe SDK: $stripe->paymentIntents->create(...)
            if ($this->isStripeResourceCall($node)) {
                return "Stripe::{$method}()";
            }

            if (in_array($method, ['post', 'put', 'patch', 'delete'], true) && $this->isHttpCallChain($node->var)) {
                return "Http::{$method}()";
            }

            if (in_array($method, ['charge', 'refund', 'invoice', 'debit', 'credit', 'transfer'], true)) {
                if ($this->receiverLooksLikeBillableOrGateway($node->var, $extraFinancialClasses)) {
                    return "->{$method}()";
                }
            }

            if ($enclosingClass !== null && $this->isMatchedInjectedProperty($node->var, $enclosingClass, $extraFinancialClasses)) {
                return "->{$method}()";
            }
        }

        return null;
    }

    /**
     * Checks if node represents a call on modern Stripe SDK:
     * $stripe->charges->create(), $this->stripe->paymentIntents->create(), etc.
     */
    public function isStripeResourceCall(MethodCall $node): bool
    {
        $method = $node->name instanceof Identifier ? $node->name->toString() : '';
        if (! in_array($method, ['create', 'update', 'capture', 'retrieve', 'refund', 'charge', 'invoice'], true)) {
            return false;
        }

        if ($node->var instanceof PropertyFetch && $node->var->name instanceof Identifier) {
            $resource = strtolower($node->var->name->toString());
            if (in_array($resource, self::STRIPE_RESOURCES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if an expression is a chain originating from an Http facade or Guzzle client.
     */
    private function isHttpCallChain(Node\Expr $expr): bool
    {
        if ($expr instanceof StaticCall && $expr->class instanceof Name) {
            $className = ltrim($expr->class->toString(), '\\');

            return $className === 'Http' || str_ends_with($className, '\\Http');
        }

        if ($expr instanceof MethodCall) {
            return $this->isHttpCallChain($expr->var);
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

    /**
     * @param  list<string>  $extraClasses
     */
    private function receiverLooksLikeBillableOrGateway(Node\Expr $expr, array $extraClasses): bool
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            $varName = strtolower($expr->name);

            return in_array($varName, ['user', 'customer', 'billable', 'wallet', 'ledger', 'gateway'], true);
        }

        return false;
    }

    /**
     * Matches injected typed properties or constructor params matching extraClasses:
     * e.g. $this->gw->capture() where private PaymentGateway $gw was injected.
     *
     * @param  list<string>  $extraClasses
     */
    private function isMatchedInjectedProperty(Node\Expr $expr, Node\Stmt\Class_ $class, array $extraClasses): bool
    {
        if ($extraClasses === []) {
            return false;
        }

        $propName = null;
        if ($expr instanceof PropertyFetch && $expr->var instanceof Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier) {
            $propName = $expr->name->toString();
        } elseif ($expr instanceof Variable && is_string($expr->name)) {
            $propName = $expr->name;
        }

        if ($propName === null) {
            return false;
        }

        // Check constructor promoted properties and parameters
        $constructor = $class->getMethod('__construct');
        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                if ($param->var instanceof Variable && $param->var->name === $propName) {
                    foreach ($this->resolveTypeNames($param->type) as $typeName) {
                        if ($this->matchesExtraClass($typeName, $extraClasses)) {
                            return true;
                        }
                    }
                }
            }
        }

        // Check class properties
        foreach ($class->getProperties() as $prop) {
            foreach ($prop->props as $property) {
                if ($property->name->toString() === $propName) {
                    foreach ($this->resolveTypeNames($prop->type) as $typeName) {
                        if ($this->matchesExtraClass($typeName, $extraClasses)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveTypeNames(Identifier|Name|Node\ComplexType|null $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof Node\NullableType) {
            return $this->resolveTypeNames($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $t) {
                $names = array_merge($names, $this->resolveTypeNames($t));
            }

            return $names;
        }

        if ($type instanceof Name) {
            return [ltrim($type->toString(), '\\')];
        }

        if ($type instanceof Identifier) {
            return [$type->toString()];
        }

        return [];
    }

    /**
     * @param  list<string>  $extraClasses
     */
    private function matchesExtraClass(string $typeName, array $extraClasses): bool
    {
        foreach ($extraClasses as $extra) {
            $clean = ltrim($extra, '\\');
            if ($typeName === $clean || str_ends_with($clean, '\\'.$typeName) || str_ends_with($typeName, '\\'.$clean)) {
                return true;
            }
        }

        return false;
    }
}
