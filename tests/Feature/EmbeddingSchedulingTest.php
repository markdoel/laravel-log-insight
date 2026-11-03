<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Jobs\EmbedClusterJob;
use MarkusDoell\LogInsight\Listeners\QueueClusterEmbedding;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EmbeddingSchedulingTest extends TestCase
{
    use FakesVoyage;
    use RefreshDatabase;

    private const LOG = __DIR__.'/../Fixtures/logs/single/laravel.log';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The listener is registered while the provider boots, so this cannot wait for setUp().
        $app['config']->set('log-insight.similarity.enabled', true);
        $app['config']->set('log-insight.similarity.api_key', 'voyage-test-key');
        $app['config']->set('log-insight.analysis.enabled', false);
    }

    #[Test]
    public function analyze_queues_an_embedding_for_every_new_cluster(): void
    {
        Queue::fake();
        config()->set('log-insight.queue.connection', 'redis');
        config()->set('log-insight.queue.name', 'insights');

        $this->command('log-insight:analyze', ['--file' => self::LOG])->assertSuccessful();

        Queue::assertPushed(EmbedClusterJob::class, 5);
        Queue::assertPushedOn('insights', EmbedClusterJob::class);
        Queue::assertPushed(EmbedClusterJob::class, static fn (EmbedClusterJob $job): bool => $job->connection === 'redis');
        Queue::assertNotPushed(AnalyzeClusterJob::class);
    }

    #[Test]
    public function the_listener_is_registered_when_enabled(): void
    {
        Event::fake();

        Event::assertListening(ClusterDetected::class, QueueClusterEmbedding::class);
    }

    #[Test]
    public function known_clusters_are_not_queued_again(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[0.6, 0.8]])]);
        $this->command('log-insight:analyze', ['--file' => self::LOG])->assertSuccessful();
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::LOG])->assertSuccessful();

        Queue::assertNotPushed(EmbedClusterJob::class);
    }

    #[Test]
    public function without_an_api_key_nothing_is_queued(): void
    {
        Queue::fake();
        config()->set('log-insight.similarity.api_key', null);

        $this->command('log-insight:analyze', ['--file' => self::LOG])->assertSuccessful();

        Queue::assertNotPushed(EmbedClusterJob::class);
    }

    #[Test]
    public function new_clusters_are_embedded_end_to_end(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[0.6, 0.8]])]);

        $this->command('log-insight:analyze', ['--file' => self::LOG])->assertSuccessful();

        Http::assertSentCount(5);
        $this->assertSame(5, ClusterEmbedding::query()->count());
        $this->assertSame(Cluster::query()->count(), ClusterEmbedding::query()->distinct()->count('cluster_id'));
    }

    #[Test]
    public function embed_queues_the_clusters_without_an_embedding(): void
    {
        Queue::fake();
        $embedded = $this->makeCluster();
        $this->embed($embedded, [1.0, 0.0]);
        $otherModel = $this->makeCluster();
        $this->embed($otherModel, [1.0, 0.0], 'voyage-3.5');
        $missing = $this->makeCluster();

        $this->command('log-insight:embed')
            ->expectsOutputToContain('Queued 2 clusters for embedding.')
            ->assertSuccessful();

        Queue::assertPushed(EmbedClusterJob::class, 2);
        Queue::assertPushed(EmbedClusterJob::class, static fn (EmbedClusterJob $job): bool => $job->clusterId === $missing->id);
        Queue::assertPushed(EmbedClusterJob::class, static fn (EmbedClusterJob $job): bool => $job->clusterId === $otherModel->id);
    }

    #[Test]
    public function embed_says_when_everything_is_embedded(): void
    {
        Queue::fake();
        $this->embed($this->makeCluster(), [1.0]);

        $this->command('log-insight:embed')
            ->expectsOutputToContain('Every cluster has an embedding already.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function embed_needs_the_feature_and_a_key(): void
    {
        Queue::fake();
        $this->makeCluster();
        config()->set('log-insight.similarity.api_key', '');

        $this->command('log-insight:embed')
            ->expectsOutputToContain('Set LOG_INSIGHT_SIMILARITY_ENABLED=true and VOYAGE_API_KEY')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
