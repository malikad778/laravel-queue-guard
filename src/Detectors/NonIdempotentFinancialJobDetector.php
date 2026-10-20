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
use Malikad\QueueGuard\Support\ExternalCallMatcher;
use Malikad\QueueGuard\Support\IdempotencySignals;
use Malikad\QueueGuard\Support\JobConfigReader;
use Malikad\QueueGuard\Support\QueueClassResolver;
use Malikad\QueueGuard\Support\StaticConfigReader;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;

final class NonIdempotentFinancialJobDetector implements Detector
{
    public function __construct(
        private readonly QueueClassResolver $classResolver = new QueueClassResolver,
        private readonly ExternalCallMatcher $callMatcher = new ExternalCallMatcher,
        private readonly IdempotencySignals $idempotencySignals = new IdempotencySignals,
        private readonly JobConfigReader $jobConfigReader = new JobConfigReader,
        private readonly StaticConfigReader $staticConfigReader = new StaticConfigReader,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG002;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];
        $horizonDefaults = $config->projectRoot !== ''
            ? $this->staticConfigReader->getHorizonDefaults($config->projectRoot)
            : ['timeout' => null, 'tries' => null];

        AstHelper::walkClasses($ast, function (Class_ $class) use (&$findings, $filePath, $config, $horizonDefaults): void {
            if (! $this->classResolver->isQueuedClass($class)) {
                return;
            }

            if ($this->docblockInspector->isExempt($class, RuleId::QG002)) {
                return;
            }

            $handleMethod = $class->getMethod('handle');
            if ($handleMethod === null || $handleMethod->stmts === null) {
                return;
            }

            if ($this->docblockInspector->isExempt($handleMethod, RuleId::QG002, [$class])) {
                return;
            }

            // If $tries === 1 explicitly, job will not retry on failure
            $explicitTries = $this->jobConfigReader->getTriesValue($class);
            if ($explicitTries === 1) {
                return;
            }

            if (! $this->jobConfigReader->canJobRetry($class, $horizonDefaults['tries'])) {
                return;
            }

            $hasPrecedingGuard = false;

            foreach ($handleMethod->stmts as $stmt) {
                // If a preceding guard statement (e.g. early return if paid, or lockForUpdate) was run before
                if ($this->idempotencySignals->isPrecedingGuardStatement($stmt)) {
                    $hasPrecedingGuard = true;
                }

                $this->scanNodeForFinancialCalls(
                    $stmt,
                    [$class, $handleMethod],
                    $class,
                    $handleMethod,
                    $hasPrecedingGuard,
                    $filePath,
                    $config,
                    $findings
                );
            }
        });

        return $findings;
    }

    /**
     * @param  list<Node>  $ancestors
     * @param  list<Finding>  $findings
     */
    private function scanNodeForFinancialCalls(
        Node $node,
        array $ancestors,
        Class_ $class,
        Node\Stmt\ClassMethod $handleMethod,
        bool $hasPrecedingGuard,
        string $filePath,
        Config $config,
        array &$findings
    ): void {
        if ($this->docblockInspector->isExempt($node, RuleId::QG002, $ancestors)) {
            return;
        }

        $matched = $this->callMatcher->matchFinancialCall($node, $config->financialCallClasses);
        if ($matched !== null) {
            $signalLevel = $this->idempotencySignals->evaluateCall(
                $class,
                $handleMethod,
                $node,
                $ancestors,
                $hasPrecedingGuard
            );

            if ($signalLevel === IdempotencySignals::STRONG) {
                return;
            }

            if ($signalLevel === IdempotencySignals::UNIQUE_LOCK_ONLY) {
                $findings[] = new Finding(
                    ruleId: RuleId::QG002,
                    severity: Severity::Warning,
                    file: $filePath,
                    line: $node->getStartLine(),
                    message: "Financial or mutating call {$matched} in a retried job relies only on a unique lock without an idempotency key.",
                    tip: 'Add an idempotency key; unique locks do not protect against retries.',
                );
            } else {
                $findings[] = new Finding(
                    ruleId: RuleId::QG002,
                    severity: $config->severityFor(RuleId::QG002),
                    file: $filePath,
                    line: $node->getStartLine(),
                    message: "Financial or mutating call {$matched} in a retried job without an idempotency key or state guard.",
                    tip: "Pass ['idempotency_key' => ...] or check database state before executing the call.",
                );
            }

            return;
        }

        $nextAncestors = array_merge($ancestors, [$node]);
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof Node) {
                $this->scanNodeForFinancialCalls(
                    $sub,
                    $nextAncestors,
                    $class,
                    $handleMethod,
                    $hasPrecedingGuard,
                    $filePath,
                    $config,
                    $findings
                );
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanNodeForFinancialCalls(
                            $item,
                            $nextAncestors,
                            $class,
                            $handleMethod,
                            $hasPrecedingGuard,
                            $filePath,
                            $config,
                            $findings
                        );
                    }
                }
            }
        }
    }
}
