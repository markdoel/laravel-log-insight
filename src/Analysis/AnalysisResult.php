<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Analysis;

final readonly class AnalysisResult
{
    public const AI_READY = 'ready';

    public const AI_DISABLED = 'disabled';

    public const AI_NOT_CONFIGURED = 'not_configured';

    /**
     * @param  list<string>  $files
     * @param  array<string, int>  $levels  entry count per level, e.g. ['error' => 3]
     * @param  list<ClusterSummary>  $clusters  most frequent first
     */
    public function __construct(
        public array $files,
        public int $entries,
        public int $exceptions,
        public array $levels,
        public array $clusters,
        public bool $dryRun,
        public int $queuedAnalyses = 0,
        public string $aiStatus = self::AI_READY,
    ) {}

    public function newClusters(): int
    {
        return count(array_filter($this->clusters, static fn (ClusterSummary $cluster): bool => $cluster->isNew === true));
    }
}
