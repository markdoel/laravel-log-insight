<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Similarity\SimilarCluster;

final readonly class Report
{
    /**
     * @param  list<ReportRow>  $rows  most frequent first
     * @param  int  $total  clusters matching the filters, before the limit
     * @param  array<int, list<SimilarCluster>|null>|null  $similar  similar clusters per cluster ID (null when the
     *                                                               cluster has no embedding yet); null leaves them out
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $limit,
        public ?CarbonImmutable $since,
        public ?Severity $minSeverity,
        public CarbonImmutable $generatedAt,
        public ?array $similar = null,
    ) {}

    /**
     * @param  array<int, list<SimilarCluster>|null>  $similar  similar clusters per cluster ID
     */
    public function withSimilar(array $similar): self
    {
        return new self($this->rows, $this->total, $this->limit, $this->since, $this->minSeverity, $this->generatedAt, $similar);
    }

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
            'clusters' => array_map($this->row(...), $this->rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReportRow $row): array
    {
        $data = $row->toArray();

        if ($this->similar !== null) {
            $similar = $this->similar[$row->cluster->id] ?? null;
            $data['similar'] = $similar === null
                ? null
                : array_map(static fn (SimilarCluster $match): array => $match->toArray(), $similar);
        }

        return $data;
    }
}
