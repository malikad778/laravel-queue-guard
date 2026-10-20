<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;

final class AstHelper
{
    /**
     * Extracts an integer value from a scalar Node, supporting both
     * php-parser 5 (PhpParser\Node\Scalar\Int_) and php-parser 4 (PhpParser\Node\Scalar\LNumber).
     */
    public static function extractIntValue(Node $node): ?int
    {
        if ($node instanceof Int_) {
            return $node->value;
        }

        // php-parser 4 support for PHPStan 1.x
        if (get_class($node) === 'PhpParser\Node\Scalar\LNumber') {
            /** @var mixed $val */
            $val = $node->value ?? null;

            return is_int($val) ? $val : null;
        }

        return null;
    }

    /**
     * Extracts a string value from a scalar String_ node.
     */
    public static function extractStringValue(Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        return null;
    }

    /**
     * Traverses nodes with ancestor tracking. Supports Class_, Enum_, Trait_, Interface_,
     * Anonymous classes, functions, closures, and arrow functions.
     *
     * @param  array<Node>  $nodes
     * @param  list<Node>|callable(Node, list<Node>): void  $ancestorsOrCallback
     * @param  (callable(Node, list<Node>): void)|null  $callback
     */
    public static function walkWithAncestors(array $nodes, array|callable $ancestorsOrCallback, ?callable $callback = null): void
    {
        if (is_callable($ancestorsOrCallback)) {
            $callback = $ancestorsOrCallback;
            $ancestors = [];
        } else {
            $ancestors = $ancestorsOrCallback;
        }

        if ($callback === null) {
            return;
        }

        foreach ($nodes as $node) {
            $callback($node, $ancestors);
            $nextAncestors = array_values(array_merge($ancestors, [$node]));

            foreach ($node->getSubNodeNames() as $name) {
                /** @var mixed $sub */
                $sub = $node->$name;
                if ($sub instanceof Node) {
                    self::walkWithAncestors([$sub], $nextAncestors, $callback);
                } elseif (is_array($sub)) {
                    $children = [];
                    foreach ($sub as $item) {
                        if ($item instanceof Node) {
                            $children[] = $item;
                        }
                    }
                    if ($children !== []) {
                        self::walkWithAncestors($children, $nextAncestors, $callback);
                    }
                }
            }
        }
    }

    /**
     * Traverses all Class_ definitions (regular and anonymous classes).
     *
     * @param  array<Node>  $nodes
     * @param  callable(Class_, list<Node>): void  $callback
     */
    public static function walkClasses(array $nodes, callable $callback): void
    {
        self::walkWithAncestors($nodes, [], function (Node $node, array $ancestors) use ($callback): void {
            if ($node instanceof Class_) {
                $callback($node, $ancestors);
            }
        });
    }

    /**
     * Traverses all class-like definitions (Class_, Enum_, Trait_, anonymous classes).
     *
     * @param  array<Node>  $nodes
     * @param  callable(ClassLike, list<Node>): void  $callback
     */
    public static function walkClassLikes(array $nodes, callable $callback): void
    {
        self::walkWithAncestors($nodes, [], function (Node $node, array $ancestors) use ($callback): void {
            if ($node instanceof ClassLike && ! $node instanceof Interface_) {
                $callback($node, $ancestors);
            }
        });
    }

    /**
     * Traverses every node recursively without ancestors.
     *
     * @param  callable(Node): void  $callback
     */
    public static function walk(Node $node, callable $callback): void
    {
        $callback($node);
        foreach ($node->getSubNodeNames() as $name) {
            /** @var mixed $sub */
            $sub = $node->$name;
            if ($sub instanceof Node) {
                self::walk($sub, $callback);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        self::walk($item, $callback);
                    }
                }
            }
        }
    }
}
