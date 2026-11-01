<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;

/**
 * The number of occurrences of a cluster on one day, in app time.
 */
final readonly class DailyCount
{
    public function __construct(
        public CarbonImmutable $date,
        public int $count,
    ) {}
}
