<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;
use MarkusDoell\LogInsight\Jobs\EmbedClusterJob;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;
use MarkusDoell\LogInsight\Similarity\ClusterEmbedder;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EmbedClusterJobTest extends TestCase
{
    use FakesVoyage;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSimilarity();
    }

    #[Test]
    public function it_embeds_and_stores_the_cluster(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[0.6, 0.8, 0.0]])]);
        $cluster = $this->makeCluster();

        EmbedClusterJob::dispatchSync($cluster->id);

        $embedding = ClusterEmbedding::query()->sole();
        $this->assertSame($cluster->id, $embedding->cluster_id);
        $this->assertSame('voyage-4', $embedding->model);
        $this->assertSame(3, $embedding->dimensions);
        $this->assertEquals([0.6, 0.8, 0.0], $embedding->vector);
    }

    #[Test]
    public function it_sends_exception_class_normalized_message_and_application_frame(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[1.0]])]);
        $cluster = $this->makeCluster();

        EmbedClusterJob::dispatchSync($cluster->id);

        $this->assertSame([
            "Illuminate\\Database\\Eloquent\\ModelNotFoundException\nNo query results for model [App\\Models\\Order] {n}\nat app/Http/Controllers/OrderController.php:24",
        ], $this->sentVoyageBody()['input'] ?? null);

        // Never the raw message or the stack trace.
        $this->assertStringNotContainsString('48213', (string) json_encode($this->sentVoyageBody()));
        $this->assertStringNotContainsString('findOrFail', (string) json_encode($this->sentVoyageBody()));
    }

    #[Test]
    public function plain_log_messages_are_described_by_their_level(): void
    {
        $cluster = $this->makeCluster([
            'type' => 'message',
            'level' => LogLevel::Critical,
            'exception_class' => null,
            'message' => 'Payment gateway returned an unexpected response',
            'app_frame' => null,
        ]);

        $this->assertSame("CRITICAL log message\nPayment gateway returned an unexpected response", resolve(ClusterEmbedder::class)->text($cluster));
    }

    #[Test]
    public function the_text_passes_the_redactor(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[1.0]])]);
        config()->set('log-insight.redaction.patterns', ['/\bORD-\d{6}\b/' => '[ORDER_ID]']);
        $cluster = $this->makeCluster([
            'exception_class' => 'RuntimeException',
            'message' => 'Login failed for jane.doe@example.com with password=hunter2 on ORD-123456 from 203.0.113.7',
        ]);

        EmbedClusterJob::dispatchSync($cluster->id);

        $sent = (string) json_encode($this->sentVoyageBody());
        $this->assertStringNotContainsString('jane.doe@example.com', $sent);
        $this->assertStringNotContainsString('hunter2', $sent);
        $this->assertStringNotContainsString('ORD-123456', $sent);
        $this->assertStringNotContainsString('203.0.113.7', $sent);
        $this->assertStringContainsString('[ORDER_ID]', $sent);
    }

    #[Test]
    public function it_skips_clusters_that_are_embedded_or_gone(): void
    {
        Http::fake();
        $cluster = $this->makeCluster();
        $this->embed($cluster, [1.0, 0.0]);

        EmbedClusterJob::dispatchSync($cluster->id);
        EmbedClusterJob::dispatchSync(999_999);

        Http::assertNothingSent();
        $this->assertSame(1, ClusterEmbedding::query()->count());
    }

    #[Test]
    public function another_model_gets_its_own_embedding(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[0.0, 1.0]], 'voyage-4-lite')]);
        config()->set('log-insight.similarity.model', 'voyage-4-lite');
        $cluster = $this->makeCluster();
        $this->embed($cluster, [1.0, 0.0], 'voyage-4');

        EmbedClusterJob::dispatchSync($cluster->id);

        $this->assertEqualsCanonicalizing(['voyage-4', 'voyage-4-lite'], $cluster->embeddings()->pluck('model')->all());
    }

    #[Test]
    public function permanent_failures_fail_the_job_without_storing_anything(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageError(401, 'Provided API key is invalid.')]);
        $cluster = $this->makeCluster();

        $job = new EmbedClusterJob($cluster->id);
        $job->handle(resolve(ClusterEmbedder::class));

        Http::assertSentCount(1);
        $this->assertSame(0, ClusterEmbedding::query()->count());
    }

    #[Test]
    public function retryable_failures_are_rethrown_for_the_queue(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageError(503, 'Service unavailable')]);
        config()->set('log-insight.similarity.timeout', 5);
        $cluster = $this->makeCluster();

        $this->expectException(EmbeddingRequestFailed::class);

        (new EmbedClusterJob($cluster->id))->handle(resolve(ClusterEmbedder::class));
    }

    #[Test]
    public function the_job_timeout_leaves_room_for_retries(): void
    {
        $job = new EmbedClusterJob(1, 30);

        $this->assertSame(120, $job->timeout);
        $this->assertSame('1', $job->uniqueId());
    }
}
