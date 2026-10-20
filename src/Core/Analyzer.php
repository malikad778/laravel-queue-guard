<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Detectors\BatchWithoutFinallyDetector;
use Malikad\QueueGuard\Detectors\DispatchInsideTransactionDetector;
use Malikad\QueueGuard\Detectors\HttpInsideTransactionDetector;
use Malikad\QueueGuard\Detectors\MissingSerializesModelsDetector;
use Malikad\QueueGuard\Detectors\NonIdempotentFinancialJobDetector;
use Malikad\QueueGuard\Detectors\RequestContextInQueuedClassDetector;
use Malikad\QueueGuard\Detectors\RetryAfterTimeoutMismatchDetector;
use Malikad\QueueGuard\Detectors\SyncDispatchInLoopDetector;
use Malikad\QueueGuard\Detectors\UnboundedJobTimeoutDetector;
use PhpParser\Error as ParserError;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class Analyzer
{
    private readonly Parser $parser;

    /** @var list<Detector> */
    private readonly array $detectors;

    /**
     * @param  list<Detector>|null  $detectors
     */
    public function __construct(?array $detectors = null)
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->detectors = $detectors ?? [
            new HttpInsideTransactionDetector,
            new NonIdempotentFinancialJobDetector,
            new MissingSerializesModelsDetector,
            new UnboundedJobTimeoutDetector,
            new BatchWithoutFinallyDetector,
            new SyncDispatchInLoopDetector,
            new DispatchInsideTransactionDetector,
            new RetryAfterTimeoutMismatchDetector,
            new RequestContextInQueuedClassDetector,
        ];
    }

    /**
     * @param  list<string>  $files
     * @return list<Finding>
     */
    public function analyseFiles(array $files, Config $config): array
    {
        $findings = [];
        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }
            $findings = array_merge($findings, $this->analyseCode($code, $file, $config));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    public function analyseCode(string $code, string $filePath, ?Config $config = null): array
    {
        $config ??= new Config;

        try {
            $ast = $this->parser->parse($code);
        } catch (ParserError $e) {
            return [
                new Finding(
                    ruleId: RuleId::PARSE_ERROR,
                    severity: Severity::Error,
                    file: $filePath,
                    line: $e->getStartLine() > 0 ? $e->getStartLine() : 1,
                    message: 'Syntax error: '.$e->getRawMessage(),
                    tip: 'Fix syntax error in PHP code so static analysis can parse the file.',
                ),
            ];
        } catch (\Throwable $e) {
            return [
                new Finding(
                    ruleId: RuleId::PARSE_ERROR,
                    severity: Severity::Error,
                    file: $filePath,
                    line: 1,
                    message: 'Failed to parse file: '.$e->getMessage(),
                    tip: 'Fix syntax error in PHP code so static analysis can parse the file.',
                ),
            ];
        }

        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, [
            'preserveOriginalNames' => true,
            'replaceNodes' => true,
        ]));

        /** @var array<Stmt> $resolvedAst */
        $resolvedAst = $traverser->traverse($ast);

        $findings = [];
        foreach ($this->detectors as $detector) {
            if (! $config->isRuleEnabled($detector->ruleId())) {
                continue;
            }

            foreach ($detector->detect($resolvedAst, $filePath, $config) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
