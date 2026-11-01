<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Dashboard;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Dashboard\BarChart;
use MarkusDoell\LogInsight\Reporting\DailyCount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarChartTest extends TestCase
{
    #[Test]
    public function the_busiest_day_fills_the_chart_height(): void
    {
        $chart = BarChart::fromDailyCounts($this->days([0, 5, 10]));

        $this->assertSame(120, $chart->width);
        $this->assertSame(10, $chart->max);
        $this->assertSame(15, $chart->total);
        $this->assertSame([0, 40, 80], array_column($chart->bars, 'x'));

        $this->assertSame('', $chart->bars[0]['path']);
        $this->assertSame('M44 80V44Q44 40 48 40H72Q76 40 76 44V80Z', $chart->bars[1]['path']);
        $this->assertSame('M84 80V4Q84 0 88 0H112Q116 0 116 4V80Z', $chart->bars[2]['path']);
    }

    #[Test]
    public function small_counts_stay_visible(): void
    {
        $chart = BarChart::fromDailyCounts($this->days([1, 1000]));

        // 2 units high, so the corner radius shrinks to fit.
        $this->assertSame('M4 80V80Q4 78 6 78H34Q36 78 36 80V80Z', $chart->bars[0]['path']);
    }

    #[Test]
    public function a_chart_without_occurrences_has_no_bars(): void
    {
        $chart = BarChart::fromDailyCounts($this->days([0, 0, 0]));

        $this->assertSame(0, $chart->max);
        $this->assertSame(0, $chart->total);
        $this->assertSame(['', '', ''], array_column($chart->bars, 'path'));
    }

    /**
     * @param  list<int>  $counts
     * @return list<DailyCount>
     */
    private function days(array $counts): array
    {
        $first = CarbonImmutable::parse('2026-10-24');

        return array_map(static fn (int $count, int $day): DailyCount => new DailyCount($first->addDays($day), $count), $counts, array_keys($counts));
    }
}
