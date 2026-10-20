<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use Malikad\QueueGuard\Support\AstHelper;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\QueueClassResolver;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\UnionType;

final class MissingSerializesModelsDetector implements Detector
{
    private const PRIMITIVE_TYPES = [
        'int', 'string', 'bool', 'float', 'array', 'callable', 'iterable', 'object', 'mixed', 'null',
        'DateTime', 'DateTimeImmutable', 'DateTimeInterface', 'Carbon', 'Carbon\\Carbon', 'Carbon\\CarbonImmutable',
        'Ramsey\\Uuid\\UuidInterface',
    ];

    public function __construct(
        private readonly QueueClassResolver $classResolver = new QueueClassResolver,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG003;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        AstHelper::walkClasses($ast, function (Class_ $class) use (&$findings, $filePath, $config): void {
            if (! $this->classResolver->isQueuedClass($class)) {
                return;
            }

            if ($this->docblockInspector->isExempt($class, RuleId::QG003)) {
                return;
            }

            if ($this->classResolver->usesSerializesModels($class)) {
                return;
            }

            $constructor = $class->getMethod('__construct');
            if ($constructor === null) {
                return;
            }

            if ($this->docblockInspector->isExempt($constructor, RuleId::QG003, [$class])) {
                return;
            }

            foreach ($constructor->params as $param) {
                $typeNames = $this->extractTypeNames($param->type);
                foreach ($typeNames as $typeName) {
                    if ($this->isSupportCollection($typeName)) {
                        $paramVar = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? '$'.$param->var->name : 'parameter';
                        $findings[] = new Finding(
                            ruleId: RuleId::QG003,
                            severity: Severity::Warning,
                            file: $filePath,
                            line: $param->getStartLine(),
                            message: "Queued class constructor parameter {$paramVar} ({$typeName}) may serialize large payloads without SerializesModels.",
                            tip: 'Add use SerializesModels; to the class or pass scalar IDs instead.',
                        );
                    } elseif ($this->looksLikeEloquentModelOrCollection($typeName)) {
                        $paramVar = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? '$'.$param->var->name : 'parameter';
                        $findings[] = new Finding(
                            ruleId: RuleId::QG003,
                            severity: $config->severityFor(RuleId::QG003, Severity::Error),
                            file: $filePath,
                            line: $param->getStartLine(),
                            message: "Queued class accepts model parameter {$paramVar} ({$typeName}) but does not use the SerializesModels trait.",
                            tip: 'Add use Illuminate\\Queue\\SerializesModels; to prevent serializing full model graphs into the queue payload.',
                        );
                    }
                }
            }
        });

        return $findings;
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

    private function isSupportCollection(string $typeName): bool
    {
        return $typeName === 'Illuminate\\Support\\Collection'
            || str_ends_with($typeName, '\\Support\\Collection')
            || $typeName === 'Support\\Collection';
    }

    private function looksLikeEloquentModelOrCollection(string $typeName): bool
    {
        if (in_array($typeName, self::PRIMITIVE_TYPES, true)) {
            return false;
        }

        if (
            str_contains($typeName, 'Models\\')
            || str_contains($typeName, 'Entities\\')
            || str_starts_with($typeName, 'App\\')
            || str_starts_with($typeName, 'Domain\\')
            || $typeName === 'Illuminate\\Database\\Eloquent\\Model'
            || $typeName === 'Illuminate\\Database\\Eloquent\\Collection'
            || str_ends_with($typeName, '\\Model')
            || str_ends_with($typeName, '\\Collection')
            || $typeName === 'Eloquent\\Collection'
            || $typeName === 'Model'
        ) {
            return true;
        }

        $shortName = substr(strrchr('\\'.$typeName, '\\') ?: $typeName, 1);
        $commonModels = [
            'User', 'Order', 'Customer', 'Invoice', 'Subscription', 'Payment',
            'Transaction', 'Wallet', 'Account', 'Product', 'Team', 'Organization', 'Tenant',
            'Post', 'Article', 'Comment',
        ];

        return in_array($shortName, $commonModels, true);
    }
}
