<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

use Illuminate\Contracts\Bus\Dispatcher;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * Decides which clusters are sent to the AI provider and queues them.
 * Clusters that already have an analysis, or whose analysis failed
 * permanently, are never sent again.
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

        $job = (new AnalyzeClusterJob($cluster->id, $this->requestTimeout))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        $this->bus->dispatch($job);

        return true;
    }
}
