<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Listeners;

use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Similarity\EmbeddingScheduler;

/**
 * Registered only when log-insight.similarity.enabled is true.
 */
final readonly class QueueClusterEmbedding
{
    public function __construct(
        private EmbeddingScheduler $scheduler,
    ) {}

    public function handle(ClusterDetected $event): void
    {
        $this->scheduler->schedule($event->cluster);
    }
}
