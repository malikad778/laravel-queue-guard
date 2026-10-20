<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Fixers;

use Malikad\QueueGuard\Contracts\Fixer;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DispatchCallMatcher;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\TransactionScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class AddAfterCommitFixer implements Fixer
{
    public function __construct(
        private readonly TransactionScopeFinder $scopeFinder = new TransactionScopeFinder,
        private readonly DispatchCallMatcher $dispatchMatcher = new DispatchCallMatcher,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function supports(): RuleId
    {
        return RuleId::QG007;
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

        $scopes = $this->scopeFinder->findScopes($oldStmts);
        if ($scopes === []) {
            return $code;
        }

        // Collect all statement node IDs that belong to transaction scopes
        $txStmtIds = [];
        foreach ($scopes as $scope) {
            foreach ($scope['stmts'] as $stmt) {
                $txStmtIds[spl_object_id($stmt)] = true;
            }
        }

        $oldTokens = $parser->getTokens();

        $cloner = new NodeTraverser;
        $cloner->addVisitor(new CloningVisitor);
        $newStmts = $cloner->traverse($oldStmts);

        $modified = false;

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($this->scopeFinder, $this->dispatchMatcher, $this->docblockInspector, $modified) extends NodeVisitorAbstract
        {
            public bool $modified = false;

            /** @var array<int, bool> */
            private array $inTxStack = [];

            public function __construct(
                private readonly TransactionScopeFinder $scopeFinder,
                private readonly DispatchCallMatcher $dispatchMatcher,
                private readonly DocblockInspector $docblockInspector,
                bool &$modifiedRef,
            ) {
                $this->modified = &$modifiedRef;
            }

            public function enterNode(Node $node): ?Node
            {
                if ($this->scopeFinder->isTransactionClosureCall($node)) {
                    $this->inTxStack[] = true;
                }

                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($this->scopeFinder->isTransactionClosureCall($node)) {
                    array_pop($this->inTxStack);

                    return null;
                }

                if ($this->inTxStack === []) {
                    // Not inside an active transaction closure
                    return null;
                }

                if ($this->docblockInspector->isExempt($node, RuleId::QG007)) {
                    return null;
                }

                if ($node instanceof Expression && $node->expr instanceof StaticCall) {
                    $call = $node->expr;
                    if ($call->name instanceof Identifier && $call->name->toString() === 'dispatch') {
                        // Only mutate if it is a queued dispatch and not already chained with afterCommit
                        $matched = $this->dispatchMatcher->matchDispatchCall($call);
                        if ($matched !== null && ! $this->dispatchMatcher->hasAfterCommitChain($call)) {
                            $node->expr = new MethodCall($call, new Identifier('afterCommit'));
                            $this->modified = true;
                        }
                    }
                }

                return null;
            }
        });

        $newStmts = $traverser->traverse($newStmts);
        if (! $modified) {
            return $code;
        }

        return (new Standard)->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }
}
