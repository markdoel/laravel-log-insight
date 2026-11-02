<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Similarity;

use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;
use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Embeds a cluster and stores the vector. The text is redacted before it
 * leaves the server.
 */
final readonly class ClusterEmbedder
{
    public function __construct(
        private EmbeddingClient $client,
        private Redactor $redactor,
        private InstallationCheck $installation,
    ) {}

    /**
     * What is sent to the embedding provider: exception class (or level),
     * normalized message and application frame, redacted. No stack trace,
     * no source code and no raw message.
     */
    public function text(Cluster $cluster): string
    {
        $lines = [
            $cluster->exception_class ?? strtoupper($cluster->level->value).' log message',
            $cluster->message,
        ];

        if ($cluster->app_frame !== null && $cluster->app_frame !== '') {
            $lines[] = 'at '.$cluster->app_frame;
        }

        return $this->redactor->redact(implode("\n", $lines));
    }

    /**
     * Returns the stored embedding for the configured model, creating it if needed.
     *
     * @throws EmbeddingRequestFailed
     * @throws NotInstalled
     */
    public function embed(Cluster $cluster): ClusterEmbedding
    {
        $this->installation->ensureSimilarityInstalled();

        $model = $this->client->model();

        $existing = $cluster->embeddings()->where('model', $model)->first();

        if ($existing !== null) {
            return $existing;
        }

        $vector = $this->client->embed([$this->text($cluster)])[0] ?? null;

        if ($vector === null || Vector::from($vector) === null) {
            throw EmbeddingRequestFailed::unexpectedResponse('empty embedding');
        }

        return ClusterEmbedding::query()->updateOrCreate(
            ['cluster_id' => $cluster->id, 'model' => $model],
            ['dimensions' => count($vector), 'vector' => $vector],
        );
    }
}
