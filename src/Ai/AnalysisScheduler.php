<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

use Illuminate\Contracts\Bus\Dispatcher;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * Decides which clusters are sent to the AI provider and queues them.
 * Clusters that already have an analysis are never sent again. Clusters
 * whose analysis failed permanently are only sent again on retry().
 */
final readonly class AnalysisScheduler
{
    public function __construct(
        private LlmClient $client,
        private Dispatcher $bus,
        private bool $enabled = true,
        private ?string $connection = null,
        private ?string $queue = null,
        private int $requestTimeout = 300,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function shouldAnalyze(Cluster $cluster): bool
    {
        return $this->enabled
            && $this->client->isConfigured()
            && $cluster->analysis_failed_at === null
            && ! $cluster->analyses()->exists();
    }

    /**
     * @return bool whether a job was queued
     */
    public function schedule(Cluster $cluster): bool
    {
        if (! $this->shouldAnalyze($cluster)) {
            return false;
        }

        $this->dispatch($cluster);

        return true;
    }

    /**
     * Whether retry() would queue the cluster: its analysis failed and it has none.
     */
    public function canRetry(Cluster $cluster): bool
    {
        return $this->enabled
            && $this->client->isConfigured()
            && $cluster->analysis_failed_at !== null
            && ! $cluster->analyses()->exists();
    }

    /**
     * Clears a failed analysis and queues the cluster again.
     *
     * @return bool whether a job was queued
     */
    public function retry(Cluster $cluster): bool
    {
        if (! $this->canRetry($cluster)) {
            return false;
        }

        // Conditional, so a double click or a second tab queues the cluster only once.
        $cleared = Cluster::query()
            ->whereKey($cluster->id)
            ->whereNotNull('analysis_failed_at')
            ->whereDoesntHave('analyses')
            ->update(['analysis_failed_at' => null, 'analysis_error' => null]);

        $cluster->refresh();

        if ($cleared === 0) {
            return false;
        }

        $this->dispatch($cluster);

        return true;
    }

    private function dispatch(Cluster $cluster): void
    {
        $job = (new AnalyzeClusterJob($cluster->id, $this->requestTimeout))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        $this->bus->dispatch($job);
    }
}
