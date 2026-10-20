<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Support\StaticConfigReader;
use PHPUnit\Framework\TestCase;

final class StaticConfigReaderTest extends TestCase
{
    public function test_reads_queue_config_with_env_default(): void
    {
        $reader = new StaticConfigReader;
        $code = <<<'PHP'
<?php
return [
    'default' => env('QUEUE_CONNECTION', 'redis'),
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'retry_after' => env('REDIS_QUEUE_RETRY_AFTER', 90),
        ],
    ],
];
PHP;

        $parsed = $reader->parseConfigCode($code);

        self::assertSame('redis', $parsed['default']);
        self::assertSame(90, $parsed['connections']['redis']['retry_after']);
    }
}
