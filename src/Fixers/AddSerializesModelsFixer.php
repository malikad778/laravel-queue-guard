<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Fixers;

use Malikad\QueueGuard\Contracts\Fixer;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\QueueClassResolver;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class AddSerializesModelsFixer implements Fixer
{
    public const PRIMITIVES = [
        'int', 'string', 'bool', 'float', 'array', 'callable', 'iterable', 'object', 'mixed', 'null',
        'DateTime', 'DateTimeImmutable', 'DateTimeInterface', 'Carbon', 'Carbon\\Carbon', 'Carbon\\CarbonImmutable',
    ];

    public function __construct(
        private readonly QueueClassResolver $resolver = new QueueClassResolver,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function supports(): RuleId
    {
        return RuleId::QG003;
    }

    public function fix(string $code): string
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $oldStmts = $parser->parse($code);
        } catch (\Throwable) {
            return $code;
        }

        if ($oldStmts === null) {
            return $code;
        }

        $oldTokens = $parser->getTokens();

        $cloner = new NodeTraverser;
        $cloner->addVisitor(new CloningVisitor);
        $newStmts = $cloner->traverse($oldStmts);

        $modified = false;

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($this->resolver, $this->docblockInspector, $modified) extends NodeVisitorAbstract
        {
            public bool $modified = false;

            public function __construct(
                private readonly QueueClassResolver $resolver,
                private readonly DocblockInspector $docblockInspector,
                bool &$modifiedRef,
            ) {
                $this->modified = &$modifiedRef;
            }

            public function leaveNode(Node $node): ?Node
            {
                if (! $node instanceof Class_ || ! $this->resolver->isQueuedClass($node)) {
                    return null;
                }

                if ($this->docblockInspector->isExempt($node, RuleId::QG003)) {
                    return null;
                }

                if ($this->resolver->usesSerializesModels($node)) {
                    return null;
                }

                $constructor = $node->getMethod('__construct');
                if ($constructor === null || $constructor->params === []) {
                    return null;
                }

                if ($this->docblockInspector->isExempt($constructor, RuleId::QG003, [$node])) {
                    return null;
                }

                // Check if any constructor parameter accepts an Eloquent model or collection
                $hasModelParam = false;
                foreach ($constructor->params as $param) {
                    if ($this->paramAcceptsModel($param)) {
                        $hasModelParam = true;
                        break;
                    }
                }

                if (! $hasModelParam) {
                    return null;
                }

                $traitUse = new TraitUse([new Name('\\Illuminate\\Queue\\SerializesModels')]);
                array_unshift($node->stmts, $traitUse);
                $this->modified = true;

                return $node;
            }

            private function paramAcceptsModel(Node\Param $param): bool
            {
                $types = $this->extractTypeNames($param->type);
                foreach ($types as $type) {
                    if ($this->isModelType($type)) {
                        return true;
                    }
                }

                return false;
            }

            /**
             * @return list<string>
             */
            private function extractTypeNames(?Node $typeNode): array
            {
                if ($typeNode === null) {
                    return [];
                }
                if ($typeNode instanceof NullableType) {
                    return $this->extractTypeNames($typeNode->type);
                }
                if ($typeNode instanceof UnionType || $typeNode instanceof IntersectionType) {
                    $names = [];
                    foreach ($typeNode->types as $inner) {
                        $names = array_merge($names, $this->extractTypeNames($inner));
                    }

                    return $names;
                }
                if ($typeNode instanceof Name) {
                    return [ltrim($typeNode->toString(), '\\')];
                }

                return [];
            }

            private function isModelType(string $typeName): bool
            {
                if (in_array($typeName, AddSerializesModelsFixer::PRIMITIVES, true)) {
                    return false;
                }

                if (
                    str_contains($typeName, '\\Models\\')
                    || str_starts_with($typeName, 'App\\')
                    || str_starts_with($typeName, 'Domain\\')
                    || $typeName === 'Illuminate\\Database\\Eloquent\\Model'
                    || $typeName === 'Illuminate\\Database\\Eloquent\\Collection'
                    || $typeName === 'Illuminate\\Support\\Collection'
                    || str_ends_with($typeName, 'Model')
                    || str_ends_with($typeName, 'Collection')
                ) {
                    return true;
                }

                $short = substr(strrchr('\\'.$typeName, '\\') ?: $typeName, 1);
                $common = [
                    'User', 'Order', 'Customer', 'Invoice', 'Subscription', 'Payment',
                    'Transaction', 'Wallet', 'Account', 'Product', 'Team', 'Organization', 'Tenant',
                    'Post', 'Article', 'Comment',
                ];

                return in_array($short, $common, true);
            }
        });

        $newStmts = $traverser->traverse($newStmts);
        if (! $modified) {
            return $code;
        }

        return (new Standard)->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }
}
