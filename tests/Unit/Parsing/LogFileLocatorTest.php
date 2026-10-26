<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Parsing;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Exceptions\LogFileNotFound;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogFileLocatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/log-insight-'.bin2hex(random_bytes(4));
        mkdir($this->directory);

        foreach (['laravel.log', 'laravel-2026-10-24.log', 'laravel-2026-10-25.log', 'worker.log'] as $file) {
            touch($this->directory.'/'.$file);
        }
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    #[Test]
    public function it_finds_single_and_daily_files_matching_the_pattern(): void
    {
        $files = (new LogFileLocator($this->directory))->locate();

        $this->assertSame([
            $this->directory.'/laravel-2026-10-24.log',
            $this->directory.'/laravel-2026-10-25.log',
            $this->directory.'/laravel.log',
        ], $files);
    }

    #[Test]
    public function it_skips_daily_files_older_than_since(): void
    {
        $files = (new LogFileLocator($this->directory))->locate(since: CarbonImmutable::parse('2026-10-25 08:00'));

        $this->assertSame([
            $this->directory.'/laravel-2026-10-25.log',
            $this->directory.'/laravel.log',
        ], $files);
    }

    #[Test]
    public function it_supports_a_custom_pattern(): void
    {
        $files = (new LogFileLocator($this->directory.'/', 'worker*.log'))->locate();

        $this->assertSame([$this->directory.'/worker.log'], $files);
    }

    #[Test]
    public function it_returns_an_explicit_file_as_is(): void
    {
        $file = $this->directory.'/worker.log';

        $this->assertSame([$file], (new LogFileLocator('/nowhere'))->locate($file));
    }

    #[Test]
    public function it_throws_when_the_explicit_file_is_missing(): void
    {
        $this->expectException(LogFileNotFound::class);

        (new LogFileLocator($this->directory))->locate($this->directory.'/missing.log');
    }

    #[Test]
    public function it_returns_nothing_for_an_empty_directory(): void
    {
        $this->assertSame([], (new LogFileLocator($this->directory.'/does-not-exist'))->locate());
    }
}
