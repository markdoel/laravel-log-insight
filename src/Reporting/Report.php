<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Ai\Severity;

final readonly class Report
{
    /**
     * @param  list<ReportRow>  $rows  most frequent first
     * @param  int  $total  clusters matching the filters, before the limit
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $limit,
        public ?CarbonImmutable $since,
        public ?Severity $minSeverity,
        public CarbonImmutable $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->toIso8601String(),
            'filters' => [
                'limit' => $this->limit,
                'since' => $this->since?->toIso8601String(),
                'min_severity' => $this->minSeverity?->value,
            ],
            'total' => $this->total,
            'clusters' => array_map(static fn (ReportRow $row): array => $row->toArray(), $this->rows),
        ];
    }
}
