<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Similarity\SimilarCluster;
use MarkusDoell\LogInsight\Similarity\SimilarClusters;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SimilarClustersTest extends TestCase
{
    use FakesVoyage;
    use RefreshDatabase;

    private Cluster $target;

    private Cluster $closest;

    private Cluster $close;

    private Cluster $unrelated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSimilarity();

        $this->target = $this->makeCluster();
        $this->embed($this->target, [1.0, 0.0, 0.0]);

        $this->closest = $this->makeCluster(['app_frame' => 'app/Http/Controllers/OrderController.php:31']);
        $this->embed($this->closest, [0.9, 0.1, 0.0]);   // 0.9939

        $this->close = $this->makeCluster(['app_frame' => 'app/Http/Controllers/InvoiceController.php:18']);
        $this->embed($this->close, [0.8, 0.6, 0.0]);     // 0.8

        $this->unrelated = $this->makeCluster(['exception_class' => 'TypeError']);
        $this->embed($this->unrelated, [0.0, 1.0, 0.0]); // 0
    }

    #[Test]
    public function it_finds_similar_clusters_above_the_threshold_most_similar_first(): void
    {
        $similar = $this->service()->forCluster($this->target->id, threshold: 0.75);

        $this->assertNotNull($similar);
        $this->assertSame([$this->closest->id, $this->close->id], $this->ids($similar));
        $this->assertEqualsWithDelta(0.9939, $similar[0]->similarity, 0.0001);
        $this->assertEqualsWithDelta(0.8, $similar[1]->similarity, 0.0001);
    }

    #[Test]
    public function the_configured_threshold_and_limit_apply(): void
    {
        config()->set('log-insight.similarity.threshold', 0.9);
        $this->assertSame([$this->closest->id], $this->ids($this->service()->forCluster($this->target->id) ?? []));

        config()->set('log-insight.similarity.threshold', 0.5);
        config()->set('log-insight.similarity.limit', 1);
        $this->forgetService();
        $this->assertSame([$this->closest->id], $this->ids($this->service()->forCluster($this->target->id) ?? []));
    }

    #[Test]
    public function the_cluster_itself_is_never_similar(): void
    {
        $similar = $this->service()->forCluster($this->target->id, threshold: 0.01) ?? [];

        $this->assertNotContains($this->target->id, $this->ids($similar));
    }

    #[Test]
    public function only_vectors_of_the_configured_model_and_size_are_compared(): void
    {
        $otherModel = $this->makeCluster();
        $this->embed($otherModel, [1.0, 0.0, 0.0], 'voyage-3.5');
        $otherSize = $this->makeCluster();
        $this->embed($otherSize, [1.0, 0.0]);

        $similar = $this->service()->forCluster($this->target->id, threshold: 0.75) ?? [];

        $this->assertSame([$this->closest->id, $this->close->id], $this->ids($similar));
    }

    #[Test]
    public function equal_similarity_is_ordered_by_id(): void
    {
        $twin = $this->makeCluster();
        $this->embed($twin, [0.9, 0.1, 0.0]);

        $similar = $this->service()->forCluster($this->target->id, threshold: 0.9) ?? [];

        $this->assertSame([$this->closest->id, $twin->id], $this->ids($similar));
    }

    #[Test]
    public function a_cluster_without_embedding_gives_null(): void
    {
        $fresh = $this->makeCluster();

        $this->assertNull($this->service()->forCluster($fresh->id));
        $this->assertNull($this->service()->forCluster(999_999));
    }

    #[Test]
    public function many_clusters_are_served_at_once(): void
    {
        $fresh = $this->makeCluster();

        $result = $this->service()->forClusters([$this->target->id, $this->unrelated->id, $fresh->id], threshold: 0.75);

        $this->assertSame([$this->target->id, $this->unrelated->id, $fresh->id], array_keys($result));
        $this->assertSame([$this->closest->id, $this->close->id], $this->ids($result[$this->target->id] ?? []));
        $this->assertSame([], $result[$this->unrelated->id]);
        $this->assertNull($result[$fresh->id]);
    }

    #[Test]
    public function the_result_carries_the_cluster_and_its_status(): void
    {
        $this->makeAnalysis($this->closest);

        $match = ($this->service()->forCluster($this->target->id) ?? [])[0] ?? null;

        $this->assertInstanceOf(SimilarCluster::class, $match);
        $this->assertSame('analyzed', $match->status());
        $this->assertSame([
            'id' => $this->closest->id,
            'similarity' => 0.9939,
            'exception_class' => 'Illuminate\Database\Eloquent\ModelNotFoundException',
            'level' => 'error',
            'message' => 'No query results for model [App\Models\Order] {n}',
            'location' => 'app/Http/Controllers/OrderController.php:31',
            'occurrences' => 2,
            'last_seen_at' => $this->closest->last_seen_at->toIso8601String(),
            'status' => 'analyzed',
        ], $match->toArray());
    }

    #[Test]
    public function it_is_enabled_only_with_a_key(): void
    {
        $this->assertTrue($this->service()->isEnabled());

        config()->set('log-insight.similarity.api_key', null);
        $this->forgetService();

        $this->assertFalse($this->service()->isEnabled());
    }

    #[Test]
    public function an_invalid_configured_threshold_fails_loudly(): void
    {
        config()->set('log-insight.similarity.threshold', 1.5);

        $this->expectException(LogInsightException::class);
        $this->expectExceptionMessage('greater than 0 and at most 1');

        $this->service();
    }

    #[Test]
    public function an_invalid_threshold_does_not_matter_while_disabled(): void
    {
        config()->set('log-insight.similarity.enabled', false);
        config()->set('log-insight.similarity.threshold', 'high');

        $this->assertFalse($this->service()->isEnabled());
        $this->command('log-insight:report', ['--json' => true])->assertSuccessful();
    }

    #[Test]
    public function an_invalid_threshold_argument_is_rejected(): void
    {
        $this->expectException(LogInsightException::class);

        $this->service()->forCluster($this->target->id, threshold: 0.0);
    }

    /**
     * @param  list<SimilarCluster>  $similar
     * @return list<int>
     */
    private function ids(array $similar): array
    {
        return array_map(static fn (SimilarCluster $match): int => $match->cluster->id, $similar);
    }

    private function service(): SimilarClusters
    {
        return resolve(SimilarClusters::class);
    }

    private function forgetService(): void
    {
        app()->forgetInstance(SimilarClusters::class);
        app()->forgetInstance(EmbeddingClient::class);
    }
}
