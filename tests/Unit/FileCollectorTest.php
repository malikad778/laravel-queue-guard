<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Tests\Unit;

use Malikad\QueueGuard\Core\FileCollector;
use PHPUnit\Framework\TestCase;

final class FileCollectorTest extends TestCase
{
    private FileCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new FileCollector;
    }

    public function test_exclude_directory_boundary_matching(): void
    {
        self::assertTrue($this->collector->isExcluded('app/Legacy/Old.php', ['app/Legacy']));
        self::assertTrue($this->collector->isExcluded('app/Legacy/Sub/Old.php', ['app/Legacy']));
        self::assertTrue($this->collector->isExcluded('Legacy/Old.php', ['app/Legacy'], 'app'));

        // 'tests' should exclude files inside tests directory, but NOT LatestsReport.php
        self::assertTrue($this->collector->isExcluded('tests/Feature/UserTest.php', ['tests']));
        self::assertTrue($this->collector->isExcluded('c:/app/tests/UserTest.php', ['tests']));
        self::assertFalse($this->collector->isExcluded('app/Services/LatestsReport.php', ['tests']));
        self::assertFalse($this->collector->isExcluded('app/tests_helper.php', ['tests']));
    }

    public function test_explicit_file_arguments_respect_excludes(): void
    {
        // When an explicit file path is passed, if it matches exclude patterns, it is omitted
        $files = $this->collector->collect([__FILE__], ['Unit']);
        self::assertEmpty($files);

        // When not matching exclude patterns, it is collected
        $files = $this->collector->collect([__FILE__], ['NonExistentDir']);
        self::assertCount(1, $files);
    }
}
