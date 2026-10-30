<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Reporting\DailyCount;

/**
 * Geometry of the inline SVG bar chart on the cluster page: one bar per
 * day, anchored to the baseline, with a rounded top.
 */
final readonly class BarChart
{
    public const HEIGHT = 80;

    private const SLOT = 40;

    private const GAP = 8;

    private const RADIUS = 4;

    /** Days with occurrences stay visible next to a much busier day. */
    private const MIN_BAR = 2;

    /**
     * @param  list<array{date: CarbonImmutable, count: int, x: int, path: string}>  $bars
     */
    private function __construct(
        public array $bars,
        public int $width,
        public int $max,
        public int $total,
    ) {}

    /**
     * @param  list<DailyCount>  $days
     */
    public static function fromDailyCounts(array $days): self
    {
        $max = max(0, ...array_map(static fn (DailyCount $day): int => $day->count, $days));
        $bars = [];

        foreach ($days as $index => $day) {
            $x = $index * self::SLOT;

            $bars[] = [
                'date' => $day->date,
                'count' => $day->count,
                'x' => $x,
                'path' => self::barPath($x + self::GAP / 2, self::SLOT - self::GAP, self::barHeight($day->count, $max)),
            ];
        }

        return new self(
            bars: $bars,
            width: count($days) * self::SLOT,
            max: $max,
            total: array_sum(array_map(static fn (DailyCount $day): int => $day->count, $days)),
        );
    }

    public function slot(): int
    {
        return self::SLOT;
    }

    private static function barHeight(int $count, int $max): float
    {
        if ($count === 0 || $max === 0) {
            return 0.0;
        }

        return max(self::MIN_BAR, $count / $max * self::HEIGHT);
    }

    /**
     * A bar from the baseline up, with only its top corners rounded.
     */
    private static function barPath(float $x, float $width, float $height): string
    {
        if ($height <= 0) {
            return '';
        }

        $radius = min(self::RADIUS, $height, $width / 2);
        $bottom = self::HEIGHT;
        $top = $bottom - $height;
        $right = $x + $width;

        return sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            ...array_map(self::number(...), [
                $x, $bottom,
                $top + $radius,
                $x, $top, $x + $radius, $top,
                $right - $radius,
                $right, $top, $right, $top + $radius,
                $bottom,
            ]),
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
