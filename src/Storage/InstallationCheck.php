<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Storage;

use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;

/**
 * Turns a forgotten migration into a helpful message instead of an SQL error.
 */
final class InstallationCheck
{
    private bool $verified = false;

    private bool $similarityVerified = false;

    public function ensureInstalled(): void
    {
        if ($this->verified) {
            return;
        }

        $cluster = new Cluster;

        if (! $cluster->getConnection()->getSchemaBuilder()->hasTable($cluster->getTable())) {
            throw NotInstalled::missingTables();
        }

        $this->verified = true;
    }

    /**
     * The embeddings table comes with its own, separately published migration.
     */
    public function ensureSimilarityInstalled(): void
    {
        $this->ensureInstalled();

        if ($this->similarityVerified) {
            return;
        }

        $embedding = new ClusterEmbedding;

        if (! $embedding->getConnection()->getSchemaBuilder()->hasTable($embedding->getTable())) {
            throw NotInstalled::missingEmbeddingsTable();
        }

        $this->similarityVerified = true;
    }
}
