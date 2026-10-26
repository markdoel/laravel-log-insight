<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Reporting;

use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * One cluster in a report, with its latest analysis if there is one.
 */
final readonly class ReportRow
{
    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public Cluster $cluster,
        public ?Analysis $analysis,
    ) {}

    public static function fromCluster(Cluster $cluster): self
    {
        $analysis = $cluster->latestAnalysis;

        return new self($cluster, $analysis instanceof Analysis ? $analysis : null);
    }

    public function status(): string
    {
        return match (true) {
            $this->analysis !== null => self::STATUS_ANALYZED,
            $this->cluster->analysis_failed_at !== null => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $cluster = $this->cluster;

        return [
            'id' => $cluster->id,
            'fingerprint' => $cluster->fingerprint,
            'type' => $cluster->type,
            'level' => $cluster->level->value,
            'exception_class' => $cluster->exception_class,
            'message' => $cluster->message,
            'sample_message' => $cluster->sample_message,
            'location' => $cluster->app_frame,
            'file' => $cluster->file,
            'line' => $cluster->line,
            'occurrences' => $cluster->occurrence_count,
            'first_seen_at' => $cluster->first_seen_at->toIso8601String(),
            'last_seen_at' => $cluster->last_seen_at->toIso8601String(),
            'status' => $this->status(),
            'analysis' => $this->analysis === null ? null : [
                'severity' => $this->analysis->severity->value,
                'confidence' => $this->analysis->confidence,
                'root_cause' => $this->analysis->root_cause,
                'suggested_fix' => $this->analysis->suggested_fix,
                'affected_files' => $this->analysis->affected_files,
                'provider' => $this->analysis->provider,
                'model' => $this->analysis->model,
                'analyzed_at' => $this->analysis->created_at?->toIso8601String(),
            ],
            'analysis_error' => $cluster->analysis_error,
        ];
    }
}
