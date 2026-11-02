<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;
use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Similarity\ClusterEmbedder;

/**
 * Embeds one cluster so that similar clusters can be found.
 */
final class EmbedClusterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $clusterId,
        int $requestTimeout = 30,
    ) {
        // Leave room for the provider's own retries inside one attempt.
        $this->timeout = $requestTimeout * 3 + 30;
    }

    public function uniqueId(): string
    {
        return (string) $this->clusterId;
    }

    public function handle(ClusterEmbedder $embedder): void
    {
        $cluster = Cluster::query()->find($this->clusterId);

        if ($cluster === null) {
            return;
        }

        try {
            $embedder->embed($cluster);
        } catch (EmbeddingRequestFailed $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (NotInstalled $exception) {
            // Retrying cannot help until the migration has been run.
            $this->fail($exception);
        }
    }
}
