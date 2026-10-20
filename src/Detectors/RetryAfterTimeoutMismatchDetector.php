<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\AstHelper;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\JobConfigReader;
use Malikad\QueueGuard\Support\QueueClassResolver;
use Malikad\QueueGuard\Support\StaticConfigReader;
use PhpParser\Node\Stmt\Class_;

final class RetryAfterTimeoutMismatchDetector implements Detector
{
    public function __construct(
        private readonly QueueClassResolver $classResolver = new QueueClassResolver,
        private readonly JobConfigReader $jobConfigReader = new JobConfigReader,
        private readonly StaticConfigReader $staticConfigReader = new StaticConfigReader,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG008;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        AstHelper::walkClasses($ast, function (Class_ $class) use (&$findings, $filePath, $config): void {
            if (! $this->classResolver->isQueuedClass($class)) {
                return;
            }

            if ($this->docblockInspector->isExempt($class, RuleId::QG008)) {
                return;
            }

            $timeout = $this->jobConfigReader->getTimeoutValue($class);
            if ($timeout === null) {
                return;
            }

            $connName = $this->jobConfigReader->getConnectionName($class);
            $retryAfter = null;

            if ($config->projectRoot !== '') {
                $retryAfter = $this->staticConfigReader->getRetryAfterForConnection($config->projectRoot, $connName);
            } else {
                // When analyzing standalone code without a project root / config file, default to Laravel standard retry_after (90s)
                $retryAfter = 90;
            }

            if ($retryAfter === null) {
                return;
            }

            if ($timeout >= $retryAfter) {
                $propNode = $class->getProperty('timeout');
                $line = $propNode !== null ? $propNode->getStartLine() : $class->getStartLine();
                $className = $class->name !== null ? $class->name->toString() : 'Job';
                $connDisplay = $connName !== null ? "for connection '{$connName}'" : 'for default queue connection';

                $findings[] = new Finding(
                    ruleId: RuleId::QG008,
                    severity: $config->severityFor(RuleId::QG008),
                    file: $filePath,
                    line: $line,
                    message: "Job {$className} defines \$timeout = {$timeout}s which is >= queue retry_after ({$retryAfter}s) {$connDisplay}, risking duplicate concurrent execution.",
                    tip: "Increase 'retry_after' in config/queue.php above {$timeout} or lower the job \$timeout.",
                );
            }
        });

        return $findings;
    }
}
