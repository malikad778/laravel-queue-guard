<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\RuleId;
use Malikad\QueueGuard\Support\DocblockInspector;
use PHPUnit\Framework\TestCase;

final class DocblockInspectorTest extends TestCase
{
    public function test_exempts_all_rules_when_blank_queue_safe_tag_used(): void
    {
        $inspector = new DocblockInspector;

        self::assertTrue($inspector->commentExempts('/** @queue-safe */', RuleId::QG001));
        self::assertTrue($inspector->commentExempts('/** @queue-safe */', RuleId::QG006));
    }

    public function test_exempts_specific_rule_only(): void
    {
        $inspector = new DocblockInspector;

        self::assertTrue($inspector->commentExempts('/** @queue-safe QG001 */', RuleId::QG001));
        self::assertFalse($inspector->commentExempts('/** @queue-safe QG001 */', RuleId::QG002));
    }

    public function test_exempts_comma_separated_rule_ids(): void
    {
        $inspector = new DocblockInspector;

        self::assertTrue($inspector->commentExempts('/** @queue-safe QG001,QG006 */', RuleId::QG001));
        self::assertTrue($inspector->commentExempts('/** @queue-safe QG001,QG006 */', RuleId::QG006));
        self::assertFalse($inspector->commentExempts('/** @queue-safe QG001,QG006 */', RuleId::QG003));
    }
}
