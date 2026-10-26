<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Queries the stored clusters for a report, most frequent first.
 */
final readonly class ClusterReport
{
    public function __construct(
        private InstallationCheck $installation,
    ) {}

    public function build(int $limit = 10, ?CarbonImmutable $since = null, ?Severity $minSeverity = null): Report
    {
        $this->installation->ensureInstalled();

        $query = Cluster::query()
            ->when($since !== null, fn (Builder $query) => $query->where('last_seen_at', '>=', $since?->setTimezone(date_default_timezone_get())))
            ->when($minSeverity !== null, fn (Builder $query) => $query->whereHas(
                'latestAnalysis',
                fn (Builder $analysis) => $analysis->whereIn('severity', array_map(
                    static fn (Severity $severity): string => $severity->value,
                    $minSeverity?->andAbove() ?? [],
                )),
            ));

        $total = (clone $query)->count();

        $clusters = $query
            ->with('latestAnalysis')
            ->orderByDesc('occurrence_count')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return new Report(
            rows: array_values($clusters->map(static fn (Cluster $cluster): ReportRow => ReportRow::fromCluster($cluster))->all()),
            total: $total,
            limit: $limit,
            since: $since,
            minSeverity: $minSeverity,
            generatedAt: CarbonImmutable::now(),
        );
    }
}
