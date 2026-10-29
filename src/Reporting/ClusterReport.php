<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Queries the stored clusters for a report, most frequent first.
 */
final readonly class ClusterReport
{
    public const STATUSES = [ReportRow::STATUS_ANALYZED, ReportRow::STATUS_PENDING, ReportRow::STATUS_FAILED];

    public function __construct(
        private InstallationCheck $installation,
    ) {}

    public function build(int $limit = 10, ?CarbonImmutable $since = null, ?Severity $minSeverity = null): Report
    {
        $this->installation->ensureInstalled();

        $query = $this->query($since, $minSeverity, null);

        $total = (clone $query)->count();

        $clusters = $this->ordered($query)
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

    /**
     * One page of clusters, most frequent first, in the same order as build().
     *
     * @param  string|null  $status  one of the ReportRow::STATUS_* constants
     * @return LengthAwarePaginator<int, ReportRow>
     */
    public function paginate(
        int $perPage = 25,
        int $page = 1,
        ?CarbonImmutable $since = null,
        ?Severity $minSeverity = null,
        ?string $status = null,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        if ($perPage < 1) {
            throw new LogInsightException('The number of clusters per page must be a positive whole number.');
        }

        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new LogInsightException("Unsupported cluster status [{$status}]. Use one of: ".implode(', ', self::STATUSES).'.');
        }

        $this->installation->ensureInstalled();

        $query = $this->query($since, $minSeverity, $status);

        $total = (clone $query)->count();
        $page = max(1, $page);

        $clusters = $this->ordered($query)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return new LengthAwarePaginator(
            items: array_values($clusters->map(static fn (Cluster $cluster): ReportRow => ReportRow::fromCluster($cluster))->all()),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    /**
     * @return Builder<Cluster>
     */
    private function query(?CarbonImmutable $since, ?Severity $minSeverity, ?string $status): Builder
    {
        return Cluster::query()
            ->when($since !== null, fn (Builder $query) => $query->where('last_seen_at', '>=', $since?->setTimezone(date_default_timezone_get())))
            ->when($minSeverity !== null, fn (Builder $query) => $query->whereHas(
                'latestAnalysis',
                fn (Builder $analysis) => $analysis->whereIn('severity', array_map(
                    static fn (Severity $severity): string => $severity->value,
                    $minSeverity?->andAbove() ?? [],
                )),
            ))
            // Mirrors ReportRow::status(): an analysis wins over a recorded failure.
            ->when($status === ReportRow::STATUS_ANALYZED, fn (Builder $query) => $query->has('analyses'))
            ->when($status === ReportRow::STATUS_FAILED, fn (Builder $query) => $query->doesntHave('analyses')->whereNotNull('analysis_failed_at'))
            ->when($status === ReportRow::STATUS_PENDING, fn (Builder $query) => $query->doesntHave('analyses')->whereNull('analysis_failed_at'));
    }

    /**
     * @param  Builder<Cluster>  $query
     * @return Builder<Cluster>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->with('latestAnalysis')
            ->orderByDesc('occurrence_count')
            ->orderByDesc('last_seen_at')
            ->orderBy('id');
    }
}
