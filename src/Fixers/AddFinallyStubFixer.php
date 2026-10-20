<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Fixers;

use Malikad\QueueGuard\Contracts\Fixer;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DocblockInspector;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class AddFinallyStubFixer implements Fixer
{
    public function __construct(
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function supports(): RuleId
    {
        return RuleId::QG005;
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
        $traverser->addVisitor(new class($this->docblockInspector, $modified) extends NodeVisitorAbstract
        {
            public bool $modified = false;

            public function __construct(
                private readonly DocblockInspector $docblockInspector,
                bool &$modifiedRef,
            ) {
                $this->modified = &$modifiedRef;
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($this->docblockInspector->isExempt($node, RuleId::QG005)) {
                    return null;
                }

                if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'dispatch') {
                    if ($this->isUnhandledBusBatchChain($node->var)) {
                        $closure = new Closure([
                            'params' => [
                                new Param(
                                    var: new Variable('batch'),
                                    type: new FullyQualified('Illuminate\\Bus\\Batch')
                                ),
                            ],
                            'stmts' => [],
                        ]);

                        $node->var = new MethodCall($node->var, new Identifier('finally'), [new Arg($closure)]);
                        $this->modified = true;
                    }
                }

                return null;
            }

            private function isUnhandledBusBatchChain(Node\Expr $expr): bool
            {
                $cur = $expr;
                while ($cur instanceof MethodCall) {
                    if ($cur->name instanceof Identifier && in_array($cur->name->toString(), ['finally', 'catch'], true)) {
                        return false;
                    }
                    $cur = $cur->var;
                }

                if ($cur instanceof StaticCall && $cur->class instanceof Node\Name && $cur->name instanceof Identifier) {
                    $cls = ltrim($cur->class->toString(), '\\');

                    return ($cls === 'Bus' || str_ends_with($cls, '\\Bus')) && $cur->name->toString() === 'batch';
                }

                return false;
            }
        });

        $newStmts = $traverser->traverse($newStmts);
        if (! $modified) {
            return $code;
        }

        return (new Standard)->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }
}
