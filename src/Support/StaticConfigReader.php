<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;

final class StaticConfigReader
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * Parses a PHP configuration file returning an array literal without executing code.
     * Evaluates env('KEY', default) and type casts.
     *
     * @return array<string, mixed>
     */
    public function readConfigFile(string $filePath): array
    {
        if (! is_file($filePath)) {
            return [];
        }

        $real = realpath($filePath) ?: $filePath;
        if (isset($this->cache[$real])) {
            return $this->cache[$real];
        }

        $code = file_get_contents($real);
        if ($code === false) {
            return [];
        }

        $parsed = $this->parseConfigCode($code);
        $this->cache[$real] = $parsed;

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseConfigCode(string $code): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $stmts = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                $evaluated = $this->evaluateArray($stmt->expr);

                /** @var array<string, mixed> $evaluated */
                return $evaluated;
            }
        }

        return [];
    }

    /**
     * Returns full queue config from config/queue.php:
     * ['default' => 'redis', 'connections' => [...]]
     *
     * @return array{default: string, connections: array<string, array{driver: ?string, retry_after: ?int, after_commit: bool}>}
     */
    public function getQueueConfig(string $projectRoot): array
    {
        $queueFile = rtrim($projectRoot, '/\\').'/config/queue.php';
        $data = $this->readConfigFile($queueFile);

        $default = isset($data['default']) && is_string($data['default']) ? $data['default'] : 'sync';
        $rawConns = isset($data['connections']) && is_array($data['connections']) ? $data['connections'] : [];

        $connections = [];
        foreach ($rawConns as $name => $cfg) {
            if (is_string($name) && is_array($cfg)) {
                $driver = isset($cfg['driver']) && is_string($cfg['driver']) ? $cfg['driver'] : null;
                $retryAfter = isset($cfg['retry_after']) && is_numeric($cfg['retry_after']) ? (int) $cfg['retry_after'] : null;
                $afterCommit = isset($cfg['after_commit']) && is_bool($cfg['after_commit']) ? $cfg['after_commit'] : false;

                $connections[$name] = [
                    'driver' => $driver,
                    'retry_after' => $retryAfter,
                    'after_commit' => $afterCommit,
                ];
            }
        }

        return [
            'default' => $default,
            'connections' => $connections,
        ];
    }

    /**
     * Returns the retry_after value for a given connection or default connection.
     */
    public function getRetryAfterForConnection(string $projectRoot, ?string $connectionName = null): ?int
    {
        $config = $this->getQueueConfig($projectRoot);
        $connName = $connectionName ?? $config['default'];

        if (isset($config['connections'][$connName]['retry_after'])) {
            return $config['connections'][$connName]['retry_after'];
        }

        return null;
    }

    /**
     * Checks if a connection has after_commit => true configured.
     */
    public function isConnectionAfterCommit(string $projectRoot, ?string $connectionName = null): bool
    {
        $config = $this->getQueueConfig($projectRoot);
        $connName = $connectionName ?? $config['default'];

        return $config['connections'][$connName]['after_commit'] ?? false;
    }

    /**
     * Returns supervisor timeouts and tries from config/horizon.php.
     * Evaluates environments overrides then defaults.
     *
     * @return array{timeout: ?int, tries: ?int}
     */
    public function getHorizonDefaults(string $projectRoot, string $environment = 'production'): array
    {
        $horizonFile = rtrim($projectRoot, '/\\').'/config/horizon.php';
        $data = $this->readConfigFile($horizonFile);

        $timeout = null;
        $tries = null;

        // Check environment-specific supervisors first
        if (isset($data['environments']) && is_array($data['environments']) && isset($data['environments'][$environment]) && is_array($data['environments'][$environment])) {
            foreach ($data['environments'][$environment] as $supervisor) {
                if (is_array($supervisor)) {
                    if (isset($supervisor['timeout']) && is_numeric($supervisor['timeout'])) {
                        $timeout = (int) $supervisor['timeout'];
                    }
                    if (isset($supervisor['tries']) && is_numeric($supervisor['tries'])) {
                        $tries = (int) $supervisor['tries'];
                    }
                }
            }
        }

        // Fall back to defaults
        if ($timeout === null && isset($data['defaults']) && is_array($data['defaults'])) {
            foreach ($data['defaults'] as $supervisor) {
                if (is_array($supervisor)) {
                    if (isset($supervisor['timeout']) && is_numeric($supervisor['timeout'])) {
                        $timeout = (int) $supervisor['timeout'];
                    }
                    if (isset($supervisor['tries']) && is_numeric($supervisor['tries'])) {
                        $tries = (int) $supervisor['tries'];
                    }
                }
            }
        }

        return ['timeout' => $timeout, 'tries' => $tries];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function evaluateArray(Array_ $array): array
    {
        $result = [];
        $autoIndex = 0;

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            $value = $this->evaluateExpr($item->value);
            if ($item->key !== null) {
                $key = $this->evaluateExpr($item->key);
                if (is_int($key) || is_string($key)) {
                    $result[$key] = $value;
                }
            } else {
                $result[$autoIndex++] = $value;
            }
        }

        return $result;
    }

    private function evaluateExpr(Node\Expr $expr): mixed
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        $intVal = AstHelper::extractIntValue($expr);
        if ($intVal !== null) {
            return $intVal;
        }

        if ($expr instanceof Float_) {
            return $expr->value;
        }

        if ($expr instanceof Array_) {
            return $this->evaluateArray($expr);
        }

        // Support type casts like (int) env('REDIS_RETRY_AFTER', 90)
        if ($expr instanceof Cast\Int_) {
            $inner = $this->evaluateExpr($expr->expr);

            return is_numeric($inner) ? (int) $inner : null;
        }

        if ($expr instanceof Cast\String_) {
            $inner = $this->evaluateExpr($expr->expr);

            return is_scalar($inner) ? (string) $inner : null;
        }

        if ($expr instanceof Cast\Bool_) {
            $inner = $this->evaluateExpr($expr->expr);

            return (bool) $inner;
        }

        if ($expr instanceof ConstFetch) {
            $lower = strtolower($expr->name->toString());
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
            if ($lower === 'null') {
                return null;
            }
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $fnName = strtolower($expr->name->toString());
            // env('KEY', default)
            if ($fnName === 'env') {
                if (isset($expr->args[1]) && $expr->args[1] instanceof Node\Arg) {
                    return $this->evaluateExpr($expr->args[1]->value);
                }

                return null;
            }
        }

        return null;
    }
}
