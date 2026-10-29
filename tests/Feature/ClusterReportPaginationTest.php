<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Reporting\ClusterReport;
use MarkusDoell\LogInsight\Reporting\ReportRow;
use MarkusDoell\LogInsight\Tests\Concerns\CreatesClusters;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterReportPaginationTest extends TestCase
{
    use CreatesClusters;
    use RefreshDatabase;

    #[Test]
    public function it_pages_through_clusters_in_report_order(): void
    {
        $ids = [];

        foreach ([5, 4, 3, 2, 1] as $count) {
            $ids[] = $this->makeCluster(['occurrence_count' => $count])->id;
        }

        $report = app(ClusterReport::class);

        $first = $report->paginate(perPage: 2, page: 1);
        $last = $report->paginate(perPage: 2, page: 3);

        $this->assertSame(5, $first->total());
        $this->assertSame(3, $first->lastPage());
        $this->assertSame(array_slice($ids, 0, 2), $this->ids($first->items()));
        $this->assertSame([$ids[4]], $this->ids($last->items()));
        $this->assertSame([], $report->paginate(perPage: 2, page: 4)->items());
        $this->assertSame(
            array_map(static fn (ReportRow $row): int => $row->cluster->id, $report->build(limit: 2)->rows),
            $this->ids($first->items()),
        );
    }

    #[Test]
    public function it_combines_all_filters(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

        $recentCritical = $this->makeCluster(['last_seen_at' => CarbonImmutable::parse('2026-10-26 11:00:00')]);
        $this->makeAnalysis($recentCritical, ['severity' => Severity::Critical]);
        $oldCritical = $this->makeCluster(['last_seen_at' => CarbonImmutable::parse('2026-10-01 11:00:00')]);
        $this->makeAnalysis($oldCritical, ['severity' => Severity::Critical]);
        $this->makeCluster(['last_seen_at' => CarbonImmutable::parse('2026-10-26 11:00:00')]);

        $page = app(ClusterReport::class)->paginate(
            since: CarbonImmutable::parse('2026-10-25 12:00:00'),
            minSeverity: Severity::High,
            status: ReportRow::STATUS_ANALYZED,
        );

        $this->assertSame([$recentCritical->id], $this->ids($page->items()));
    }

    #[Test]
    public function it_rejects_invalid_arguments(): void
    {
        $report = app(ClusterReport::class);

        try {
            $report->paginate(status: 'done');
            $this->fail('An unknown status must be rejected.');
        } catch (LogInsightException $exception) {
            $this->assertStringContainsString('analyzed, pending, failed', $exception->getMessage());
        }

        $this->expectException(LogInsightException::class);
        $report->paginate(perPage: 0);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $this->assertInstanceOf(ReportRow::class, $row);
            $ids[] = $row->cluster->id;
        }

        return $ids;
    }
}
