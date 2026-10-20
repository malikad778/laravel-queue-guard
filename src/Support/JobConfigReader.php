<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;

final class JobConfigReader
{
    public function hasExplicitTimeout(Class_ $class): bool
    {
        if ($class->getProperty('timeout') !== null || $class->getMethod('timeout') !== null) {
            return true;
        }

        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = ltrim($attr->name->toString(), '\\');
                if (in_array($name, ['Timeout', 'JobTimeout'], true) || str_ends_with($name, '\\Timeout') || str_ends_with($name, '\\JobTimeout')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getTimeoutValue(Class_ $class): ?int
    {
        // 1. Check property
        $prop = $class->getProperty('timeout');
        if ($prop !== null && isset($prop->props[0]->default)) {
            $val = AstHelper::extractIntValue($prop->props[0]->default);
            if ($val !== null) {
                return $val;
            }
        }

        // 2. Check attributes #[Timeout(120)] or #[JobTimeout(120)]
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = ltrim($attr->name->toString(), '\\');
                if (in_array($name, ['Timeout', 'JobTimeout'], true) || str_ends_with($name, '\\Timeout') || str_ends_with($name, '\\JobTimeout')) {
                    if (isset($attr->args[0])) {
                        $val = AstHelper::extractIntValue($attr->args[0]->value);
                        if ($val !== null) {
                            return $val;
                        }
                    }
                }
            }
        }

        // 3. Check timeout() method with literal return
        $method = $class->getMethod('timeout');
        if ($method !== null && $method->stmts !== null) {
            foreach ($method->stmts as $stmt) {
                if ($stmt instanceof Return_ && $stmt->expr !== null) {
                    $val = AstHelper::extractIntValue($stmt->expr);
                    if ($val !== null) {
                        return $val;
                    }
                }
            }
        }

        return null;
    }

    public function hasBoundedRetries(Class_ $class): bool
    {
        // $backoff alone does NOT bound retries
        if (
            $class->getProperty('tries') !== null
            || $class->getProperty('maxExceptions') !== null
            || $class->getMethod('retryUntil') !== null
        ) {
            return true;
        }

        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = ltrim($attr->name->toString(), '\\');
                if (
                    in_array($name, ['Tries', 'MaxExceptions', 'RetryUntil'], true)
                    || str_ends_with($name, '\\Tries')
                    || str_ends_with($name, '\\MaxExceptions')
                    || str_ends_with($name, '\\RetryUntil')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getTriesValue(Class_ $class): ?int
    {
        $prop = $class->getProperty('tries');
        if ($prop !== null && isset($prop->props[0]->default)) {
            $val = AstHelper::extractIntValue($prop->props[0]->default);
            if ($val !== null) {
                return $val;
            }
        }

        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = ltrim($attr->name->toString(), '\\');
                if ($name === 'Tries' || str_ends_with($name, '\\Tries')) {
                    if (isset($attr->args[0])) {
                        return AstHelper::extractIntValue($attr->args[0]->value);
                    }
                }
            }
        }

        return null;
    }

    public function getConnectionName(Class_ $class): ?string
    {
        $prop = $class->getProperty('connection');
        if ($prop !== null && isset($prop->props[0]->default)) {
            return AstHelper::extractStringValue($prop->props[0]->default);
        }

        return null;
    }

    public function canJobRetry(Class_ $class, ?int $horizonDefaultTries = null): bool
    {
        $tries = $this->getTriesValue($class);
        if ($tries !== null) {
            return $tries > 1 || $tries === 0;
        }

        if ($class->getMethod('retryUntil') !== null) {
            return true;
        }

        $maxProp = $class->getProperty('maxExceptions');
        if ($maxProp !== null && isset($maxProp->props[0]->default)) {
            $val = AstHelper::extractIntValue($maxProp->props[0]->default);
            if ($val !== null && $val > 1) {
                return true;
            }
        }

        if ($horizonDefaultTries !== null && $horizonDefaultTries > 1) {
            return true;
        }

        return false;
    }

    public function hasAfterCommitProperty(Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            $name = ltrim($interface->toString(), '\\');
            if ($name === 'ShouldQueueAfterCommit' || str_ends_with($name, '\\ShouldQueueAfterCommit')) {
                return true;
            }
        }

        $prop = $class->getProperty('afterCommit');
        if ($prop !== null && isset($prop->props[0]->default)) {
            $default = $prop->props[0]->default;
            if ($default instanceof ConstFetch && strtolower($default->name->toString()) === 'true') {
                return true;
            }
        }

        return false;
    }
}
