<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Analysis;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Clustering\DetectedCluster;

/**
 * What one analysis run found for a single cluster.
 */
final readonly class ClusterSummary
{
    /**
     * @param  bool|null  $isNew  null in dry runs, where storage is not consulted
     */
    public function __construct(
        public string $fingerprint,
        public string $type,
        public string $level,
        public ?string $exceptionClass,
        public string $message,
        public ?string $appFrame,
        public int $occurrences,
        public CarbonImmutable $firstSeen,
        public CarbonImmutable $lastSeen,
        public ?bool $isNew,
    ) {}

    public static function fromDetected(DetectedCluster $cluster, ?bool $isNew): self
    {
        return new self(
            fingerprint: $cluster->fingerprint->hash,
            type: $cluster->fingerprint->type,
            level: $cluster->representative()->level->value,
            exceptionClass: $cluster->representative()->exceptionClass,
            message: $cluster->fingerprint->normalizedMessage,
            appFrame: $cluster->fingerprint->appFrame,
            occurrences: $cluster->count(),
            firstSeen: $cluster->firstSeen(),
            lastSeen: $cluster->lastSeen(),
            isNew: $isNew,
        );
    }
}
