<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Detectors;

use Malikad\QueueGuard\Contracts\Detector;
use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\Finding;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\AstHelper;
use Malikad\QueueGuard\Support\DocblockInspector;
use Malikad\QueueGuard\Support\QueueClassResolver;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

final class RequestContextInQueuedClassDetector implements Detector
{
    public function __construct(
        private readonly QueueClassResolver $classResolver = new QueueClassResolver,
        private readonly DocblockInspector $docblockInspector = new DocblockInspector,
    ) {}

    public function ruleId(): RuleId
    {
        return RuleId::QG009;
    }

    public function detect(array $ast, string $filePath, Config $config): array
    {
        $findings = [];

        AstHelper::walkClasses($ast, function (Class_ $class) use (&$findings, $filePath, $config): void {
            if (! $this->classResolver->isQueuedClass($class)) {
                return;
            }

            if ($this->docblockInspector->isExempt($class, RuleId::QG009)) {
                return;
            }

            $handle = $class->getMethod('handle');
            if ($handle === null || $handle->stmts === null) {
                return;
            }

            if ($this->docblockInspector->isExempt($handle, RuleId::QG009, [$class])) {
                return;
            }

            $classMethods = [];
            foreach ($class->getMethods() as $method) {
                $classMethods[$method->name->toString()] = $method;
            }

            $this->scanStatements(
                array_values($handle->stmts),
                [$class, $handle],
                $classMethods,
                0,
                $config->maxCallDepth,
                $filePath,
                $config,
                $findings
            );
        });

        return $findings;
    }

    /**
     * @param  list<Node>  $stmts
     * @param  list<Node>  $ancestors
     * @param  array<string, ClassMethod>  $classMethods
     * @param  list<Finding>  $findings
     */
    private function scanStatements(
        array $stmts,
        array $ancestors,
        array $classMethods,
        int $depth,
        int $maxDepth,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        foreach ($stmts as $stmt) {
            $this->scanNode($stmt, $ancestors, $classMethods, $depth, $maxDepth, $filePath, $config, $findings);
        }
    }

    /**
     * @param  list<Node>  $ancestors
     * @param  array<string, ClassMethod>  $classMethods
     * @param  list<Finding>  $findings
     */
    private function scanNode(
        Node $node,
        array $ancestors,
        array $classMethods,
        int $depth,
        int $maxDepth,
        string $filePath,
        Config $config,
        array &$findings,
    ): void {
        if ($this->docblockInspector->isExempt($node, RuleId::QG009, $ancestors)) {
            return;
        }

        $contextCall = $this->matchRequestContext($node);
        if ($contextCall !== null) {
            $findings[] = new Finding(
                ruleId: RuleId::QG009,
                severity: $config->severityFor(RuleId::QG009),
                file: $filePath,
                line: $node->getStartLine(),
                message: "HTTP request or session context ({$contextCall}) used inside queued handle() method where no HTTP request exists.",
                tip: 'Resolve the value in the controller/caller and pass it into the job constructor.',
            );

            return;
        }

        // Follow bounded $this->helper() calls
        if (
            $depth < $maxDepth
            && $node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $helperName = $node->name->toString();
            if (isset($classMethods[$helperName])) {
                $helper = $classMethods[$helperName];
                if ($helper->stmts !== null) {
                    $this->scanStatements(
                        array_values($helper->stmts),
                        array_merge($ancestors, [$node, $helper]),
                        $classMethods,
                        $depth + 1,
                        $maxDepth,
                        $filePath,
                        $config,
                        $findings
                    );
                }
            }
        }

        $nextAncestors = array_merge($ancestors, [$node]);
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof Node) {
                $this->scanNode($sub, $nextAncestors, $classMethods, $depth, $maxDepth, $filePath, $config, $findings);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanNode($item, $nextAncestors, $classMethods, $depth, $maxDepth, $filePath, $config, $findings);
                    }
                }
            }
        }
    }

    private function matchRequestContext(Node $node): ?string
    {
        // 1. Helper function calls: request(), auth(), session(), url()
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $fn = strtolower(ltrim($node->name->toString(), '\\'));
            if (in_array($fn, ['request', 'auth', 'session', 'url'], true)) {
                return "{$fn}()";
            }

            // app('request') or resolve('request')
            if (in_array($fn, ['app', 'resolve'], true) && isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                if ($node->args[0]->value instanceof String_) {
                    $val = strtolower($node->args[0]->value->value);
                    if (in_array($val, ['request', 'session'], true)) {
                        return "{$fn}('{$val}')";
                    }
                }
            }
        }

        // 2. Static facade calls: Auth::*, Request::*, Session::*, URL::*
        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $cls = ltrim($node->class->toString(), '\\');
            $method = $node->name->toString();

            if (($cls === 'Auth' || str_ends_with($cls, '\\Auth'))) {
                if (in_array($method, ['user', 'id', 'check', 'guest', 'guard'], true)) {
                    return "Auth::{$method}()";
                }
            }

            if (($cls === 'Request' || str_ends_with($cls, '\\Request')) && in_array($method, ['ip', 'input', 'user', 'header', 'all', 'bearerToken', 'url', 'fullUrl'], true)) {
                return "Request::{$method}()";
            }

            if (($cls === 'Session' || str_ends_with($cls, '\\Session')) && in_array($method, ['get', 'put', 'has', 'all'], true)) {
                return "Session::{$method}()";
            }

            if (($cls === 'URL' || str_ends_with($cls, '\\URL')) && in_array($method, ['current', 'full', 'previous'], true)) {
                return "URL::{$method}()";
            }
        }

        // 3. Method calls on Auth::guard()->user(), url()->current(), etc.
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toString();
            if (in_array($method, ['user', 'id', 'check', 'guest'], true) && $this->isAuthGuardCall($node->var)) {
                return "Auth::guard()->{$method}()";
            }

            if (in_array($method, ['current', 'full', 'previous'], true) && $this->isUrlCall($node->var)) {
                return "url()->{$method}()";
            }
        }

        // 4. Superglobals: $_GET, $_POST, $_REQUEST, $_SERVER['HTTP_*'], $_SERVER['REMOTE_ADDR']
        if ($node instanceof Variable && is_string($node->name)) {
            if (in_array($node->name, ['_GET', '_POST', '_REQUEST'], true)) {
                return "\${$node->name}";
            }
        }

        if ($node instanceof ArrayDimFetch && $node->var instanceof Variable) {
            $varName = $node->var->name;
            if (in_array($varName, ['_GET', '_POST', '_REQUEST'], true)) {
                return "\${$varName}";
            }

            if ($varName === '_SERVER') {
                if ($node->dim instanceof String_ && str_starts_with($node->dim->value, 'HTTP_')) {
                    return "\$_SERVER['{$node->dim->value}']";
                }
                if ($node->dim instanceof String_ && $node->dim->value === 'REMOTE_ADDR') {
                    return "\$_SERVER['REMOTE_ADDR']";
                }
            }
        }

        return null;
    }

    private function isAuthGuardCall(Node\Expr $expr): bool
    {
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier && $expr->name->toString() === 'guard') {
            return true;
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $cls = ltrim($expr->class->toString(), '\\');

            return ($cls === 'Auth' || str_ends_with($cls, '\\Auth')) && $expr->name->toString() === 'guard';
        }

        return false;
    }

    private function isUrlCall(Node\Expr $expr): bool
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return strtolower(ltrim($expr->name->toString(), '\\')) === 'url';
        }

        return false;
    }
}
