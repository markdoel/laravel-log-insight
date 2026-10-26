<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Exceptions\InvalidSince;
use MarkusDoell\LogInsight\Support\Since;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SinceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function durations(): iterable
    {
        yield 'minutes' => ['30m', '2026-10-26 11:30:00'];
        yield 'minutes long' => ['45min', '2026-10-26 11:15:00'];
        yield 'hours' => ['24h', '2026-10-25 12:00:00'];
        yield 'days' => ['7d', '2026-10-19 12:00:00'];
        yield 'weeks' => ['2w', '2026-10-12 12:00:00'];
        yield 'upper case and spaces' => [' 6 H ', '2026-10-26 06:00:00'];
        yield 'date' => ['2026-10-01', '2026-10-01 00:00:00'];
        yield 'date time' => ['2026-10-01 08:30', '2026-10-01 08:30:00'];
    }

    #[Test]
    #[DataProvider('durations')]
    public function it_parses_durations_and_dates(string $value, string $expected): void
    {
        $now = CarbonImmutable::parse('2026-10-26 12:00:00');

        $this->assertSame($expected, Since::parse($value, $now)->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown unit' => ['5y'];
        yield 'words' => ['yesterday'];
        yield 'invalid date' => ['2026-13-45'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rejects_invalid_values(string $value): void
    {
        $this->expectException(InvalidSince::class);

        Since::parse($value);
    }
}
