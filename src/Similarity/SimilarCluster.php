<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Similarity;

use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Reporting\ReportRow;

/**
 * A cluster with a different fingerprint whose embedding is close to another one's.
 */
final readonly class SimilarCluster
{
    /**
     * @param  float  $similarity  cosine similarity, at most 1
     */
    public function __construct(
        public Cluster $cluster,
        public float $similarity,
    ) {}

    public function status(): string
    {
        return ReportRow::fromCluster($this->cluster)->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->cluster->id,
            'similarity' => round($this->similarity, 4),
            'exception_class' => $this->cluster->exception_class,
            'level' => $this->cluster->level->value,
            'message' => $this->cluster->message,
            'location' => $this->cluster->app_frame,
            'occurrences' => $this->cluster->occurrence_count,
            'last_seen_at' => $this->cluster->last_seen_at->toIso8601String(),
            'status' => $this->status(),
        ];
    }
}
