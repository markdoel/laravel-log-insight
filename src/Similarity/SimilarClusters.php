<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Similarity;

use Illuminate\Database\Eloquent\Collection;
use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;
use MarkusDoell\LogInsight\Storage\InstallationCheck;

/**
 * Finds clusters with other fingerprints whose embeddings have a cosine
 * similarity of at least the threshold.
 *
 * The comparison runs in PHP over all stored vectors of the configured
 * model, read in chunks, so it needs no vector database. One pass serves
 * any number of clusters at once.
 */
final readonly class SimilarClusters
{
    private const CHUNK = 500;

    public function __construct(
        private EmbeddingClient $client,
        private InstallationCheck $installation,
        private bool $enabled = false,
        private float $threshold = 0.85,
        private int $limit = 5,
    ) {
        // Fail loudly on a typo, but never break installations that do not use the feature.
        if ($enabled) {
            self::validate($threshold, $limit);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->client->isConfigured();
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * The most similar clusters first, or null when the cluster has no
     * embedding of the configured model yet.
     *
     * @return list<SimilarCluster>|null
     *
     * @throws NotInstalled
     */
    public function forCluster(int $clusterId, ?int $limit = null, ?float $threshold = null): ?array
    {
        return $this->forClusters([$clusterId], $limit, $threshold)[$clusterId] ?? null;
    }

    /**
     * Similar clusters for each of the given clusters, keyed by cluster ID.
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<SimilarCluster>|null> null for clusters without an embedding yet
     *
     * @throws NotInstalled
     */
    public function forClusters(array $clusterIds, ?int $limit = null, ?float $threshold = null): array
    {
        $limit ??= $this->limit;
        $threshold ??= $this->threshold;
        self::validate($threshold, $limit);

        $this->installation->ensureSimilarityInstalled();

        $result = array_fill_keys($clusterIds, null);
        $targets = $this->targets($clusterIds);

        if ($targets === []) {
            return $result;
        }

        $scores = $this->scores($targets, $threshold);
        $clusters = $this->clusters($scores, $limit);

        foreach (array_keys($targets) as $clusterId) {
            $similar = [];

            foreach ($scores[$clusterId] ?? [] as $otherId => $similarity) {
                if (count($similar) >= $limit) {
                    break;
                }

                if (isset($clusters[$otherId])) {
                    $similar[] = new SimilarCluster($clusters[$otherId], $similarity);
                }
            }

            $result[$clusterId] = $similar;
        }

        return $result;
    }

    /**
     * @param  list<int>  $clusterIds
     * @return array<int, Vector>
     */
    private function targets(array $clusterIds): array
    {
        $targets = [];

        if ($clusterIds === []) {
            return $targets;
        }

        $embeddings = ClusterEmbedding::query()
            ->where('model', $this->model())
            ->whereIn('cluster_id', $clusterIds)
            ->get(['cluster_id', 'vector']);

        foreach ($embeddings as $embedding) {
            $vector = Vector::from($embedding->vector);

            if ($vector !== null) {
                $targets[$embedding->cluster_id] = $vector;
            }
        }

        return $targets;
    }

    /**
     * Similarity at or above the threshold per target and other cluster,
     * highest first, ties by cluster ID.
     *
     * @param  array<int, Vector>  $targets
     * @return array<int, array<int, float>>
     */
    private function scores(array $targets, float $threshold): array
    {
        $scores = [];
        $dimensions = array_values(array_unique(array_map(static fn (Vector $vector): int => $vector->dimensions(), $targets)));

        ClusterEmbedding::query()
            ->where('model', $this->model())
            ->whereIn('dimensions', $dimensions)
            ->select(['id', 'cluster_id', 'vector'])
            ->chunkById(self::CHUNK, static function (Collection $embeddings) use ($targets, $threshold, &$scores): void {
                foreach ($embeddings as $embedding) {
                    $candidate = Vector::from($embedding->vector);

                    if ($candidate === null) {
                        continue;
                    }

                    foreach ($targets as $clusterId => $target) {
                        if ($clusterId === $embedding->cluster_id) {
                            continue;
                        }

                        $similarity = $target->cosine($candidate);

                        if ($similarity !== null && $similarity >= $threshold) {
                            $scores[$clusterId][$embedding->cluster_id] = $similarity;
                        }
                    }
                }
            });

        foreach ($scores as &$candidates) {
            uksort($candidates, static fn (int $a, int $b): int => [$candidates[$b], $a] <=> [$candidates[$a], $b]);
        }

        return $scores;
    }

    /**
     * The clusters behind the top scores, with their latest analysis.
     *
     * @param  array<int, array<int, float>>  $scores
     * @return array<int, Cluster>
     */
    private function clusters(array $scores, int $limit): array
    {
        $ids = [];

        foreach ($scores as $candidates) {
            $ids = [...$ids, ...array_slice(array_keys($candidates), 0, $limit)];
        }

        if ($ids === []) {
            return [];
        }

        $clusters = [];

        foreach (Cluster::query()->with('latestAnalysis')->whereIn('id', array_values(array_unique($ids)))->get() as $cluster) {
            $clusters[$cluster->id] = $cluster;
        }

        return $clusters;
    }

    private static function validate(float $threshold, int $limit): void
    {
        if ($threshold <= 0.0 || $threshold > 1.0) {
            throw new LogInsightException("The similarity threshold must be greater than 0 and at most 1, [{$threshold}] given.");
        }

        if ($limit < 1) {
            throw new LogInsightException('The number of similar clusters must be a positive whole number.');
        }
    }
}
