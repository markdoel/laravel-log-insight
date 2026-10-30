<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use MarkusDoell\LogInsight\Models\Occurrence;

/**
 * One cluster with its latest analysis, its recent activity and its latest occurrences.
 */
final readonly class ClusterDetail
{
    /**
     * @param  list<DailyCount>  $dailyCounts  oldest day first, ending today
     * @param  list<Occurrence>  $latestOccurrences  most recent first
     */
    public function __construct(
        public ReportRow $row,
        public array $dailyCounts,
        public array $latestOccurrences,
    ) {}
}
