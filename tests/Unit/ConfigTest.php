<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\Config;
use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Core\Severity;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_boolean_rule_disabling(): void
    {
        $config = new Config(rules: [
            'QG004' => false,
            'QG005' => true,
            'QG006' => ['enabled' => false],
        ]);

        self::assertFalse($config->isRuleEnabled(RuleId::QG004));
        self::assertTrue($config->isRuleEnabled(RuleId::QG005));
        self::assertFalse($config->isRuleEnabled(RuleId::QG006));
        self::assertTrue($config->isRuleEnabled(RuleId::QG001));
    }

    public function test_from_file_validates_and_records_warnings(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qg_cfg_');
        assert(is_string($tmp));

        file_put_contents($tmp, <<<'PHP'
<?php
return [
    'rules' => [
        'QG004' => false,
        'UNKNOWN_RULE_XYZ' => true,
    ],
    'format' => 'invalid_format_here',
    'failOn' => 'invalid_severity_here',
];
PHP
        );

        $config = Config::fromFile($tmp);
        unlink($tmp);

        self::assertFalse($config->isRuleEnabled(RuleId::QG004));
        self::assertSame('console', $config->format);
        self::assertSame(Severity::Error, $config->failOn);
        self::assertCount(3, $config->warnings);
    }
}
