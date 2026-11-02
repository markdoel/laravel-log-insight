<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Similarity;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Jobs\EmbedClusterJob;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Queues clusters for embedding when similar clusters are enabled and an
 * API key is configured.
 */
final readonly class EmbeddingScheduler
{
    public function __construct(
        private EmbeddingClient $client,
        private Dispatcher $bus,
        private InstallationCheck $installation,
        private bool $enabled = false,
        private ?string $connection = null,
        private ?string $queue = null,
        private int $requestTimeout = 30,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled && $this->client->isConfigured();
    }

    /**
     * Queues a cluster. The job skips clusters that are embedded already.
     *
     * @return bool whether a job was queued
     */
    public function schedule(Cluster $cluster): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $this->dispatch($cluster->id);

        return true;
    }

    /**
     * Queues every stored cluster without an embedding of the configured
     * model, for example the clusters stored before the feature was enabled.
     *
     * @return int number of queued clusters
     */
    public function scheduleMissing(): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $this->installation->ensureSimilarityInstalled();

        $model = $this->client->model();
        $queued = 0;

        Cluster::query()
            ->whereDoesntHave('embeddings', fn (Builder $query) => $query->where('model', $model))
            ->select('id')
            ->chunkById(500, function (Collection $clusters) use (&$queued): void {
                foreach ($clusters as $cluster) {
                    $this->dispatch($cluster->id);
                    $queued++;
                }
            });

        return $queued;
    }

    private function dispatch(int $clusterId): void
    {
        $this->bus->dispatch(
            (new EmbedClusterJob($clusterId, $this->requestTimeout))
                ->onConnection($this->connection)
                ->onQueue($this->queue),
        );
    }
}
