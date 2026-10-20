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
use Malikad\QueueGuard\Support\JobConfigReader;
use Malikad\QueueGuard\Support\QueueClassResolver;
use Malikad\QueueGuard\Support\StaticConfigReader;
use PhpParser\Node\Stmt\Class_;

final class UnboundedJobTimeoutDetector implements Detector
{
    public function __construct(
        private readonly QueueClassResolver $classResolver = new QueueClassResolver,
        private readonly JobConfigReader $jobConfigReader = new JobConfigReader,
        private readonly StaticConfigReader $staticConfigReader = new StaticConfigReader,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG004;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];
        $horizonDefaults = $config->projectRoot !== ''
            ? $this->staticConfigReader->getHorizonDefaults($config->projectRoot)
            : ['timeout' => null, 'tries' => null];

        AstHelper::walkClasses($ast, function (Class_ $class) use (&$findings, $filePath, $config, $horizonDefaults): void {
            if ($class->isAbstract() || ! $this->classResolver->isQueuedClass($class)) {
                return;
            }

            if ($this->docblockInspector->isExempt($class, RuleId::QG004)) {
                return;
            }

            $hasTimeout = $this->jobConfigReader->hasExplicitTimeout($class);
            $hasRetries = $this->jobConfigReader->hasBoundedRetries($class);

            if ($hasTimeout && $hasRetries) {
                return;
            }

            $missingParts = [];
            if (! $hasTimeout) {
                $missingParts[] = '$timeout';
            }
            if (! $hasRetries) {
                $missingParts[] = '$tries / retryUntil()';
            }
            $missingStr = implode(' and ', $missingParts);
            $className = $class->name !== null ? $class->name->toString() : 'AnonymousJob';

            if ($horizonDefaults['timeout'] !== null && $horizonDefaults['tries'] !== null) {
                $findings[] = new Finding(
                    ruleId: RuleId::QG004,
                    severity: Severity::Info,
                    file: $filePath,
                    line: $class->getStartLine(),
                    message: "Queued class {$className} is missing explicit {$missingStr} (covered by Horizon supervisor defaults).",
                    tip: 'Define explicit $timeout and $tries on the job class for self-contained execution guarantees.',
                );
            } else {
                $findings[] = new Finding(
                    ruleId: RuleId::QG004,
                    severity: $config->severityFor(RuleId::QG004, Severity::Warning),
                    file: $filePath,
                    line: $class->getStartLine(),
                    message: "Queued class {$className} is missing explicit {$missingStr}.",
                    tip: 'Set public int $timeout and public int $tries (or retryUntil()) on the queued class.',
                );
            }
        });

        return $findings;
    }
}
