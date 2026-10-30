<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Loads everything the dashboard shows about a single cluster.
 */
final readonly class ClusterDetailReport
{
    public const DAYS = 14;

    public const LATEST_OCCURRENCES = 20;

    public function __construct(
        private InstallationCheck $installation,
    ) {}

    public function find(int $clusterId, int $days = self::DAYS, int $latestOccurrences = self::LATEST_OCCURRENCES): ?ClusterDetail
    {
        if ($days < 1 || $latestOccurrences < 1) {
            throw new LogInsightException('The number of days and occurrences must be positive whole numbers.');
        }

        $cluster = $this->cluster($clusterId);

        if ($cluster === null) {
            return null;
        }

        return new ClusterDetail(
            row: ReportRow::fromCluster($cluster),
            dailyCounts: $this->dailyCounts($cluster, $days),
            latestOccurrences: array_values($cluster->occurrences()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($latestOccurrences)
                ->get()
                ->all()),
        );
    }

    /**
     * Just the cluster with its latest analysis, without the occurrences.
     */
    public function cluster(int $clusterId): ?Cluster
    {
        $this->installation->ensureInstalled();

        return Cluster::query()->with('latestAnalysis')->find($clusterId);
    }

    /**
     * Counts each of the last $days days, today included. Days are calendar
     * days in app time, the time zone the database stores. One count per day
     * on the (cluster_id, occurred_at) index keeps the query plain and portable
     * across SQLite, MySQL and Postgres, without date functions or raw SQL.
     *
     * @return list<DailyCount>
     */
    private function dailyCounts(Cluster $cluster, int $days): array
    {
        $first = CarbonImmutable::now(date_default_timezone_get())->startOfDay()->subDays($days - 1);
        $counts = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $first->addDays($day);

            $counts[] = new DailyCount($date, $cluster->occurrences()
                ->where('occurred_at', '>=', $date)
                ->where('occurred_at', '<', $date->addDay())
                ->count());
        }

        return $counts;
    }
}
