<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Ai\ClusterAnalyzer;
use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Tests\Concerns\FakesAnthropic;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class AnalyzeClusterJobTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableAnthropic();
        config()->set('log-insight.llm.anthropic.timeout', 5);
    }

    #[Test]
    public function it_analyzes_the_cluster(): void
    {
        Http::fake(['*' => $this->anthropicResponse($this->analysisPayload())]);
        $cluster = $this->makeCluster();

        AnalyzeClusterJob::dispatchSync($cluster->id);

        $this->assertSame(1, $cluster->analyses()->count());
    }

    #[Test]
    public function it_skips_clusters_that_are_analyzed_or_gone(): void
    {
        Http::fake();
        $cluster = $this->makeCluster();
        $cluster->analyses()->create([
            'provider' => 'anthropic', 'model' => 'm', 'root_cause' => 'x', 'suggested_fix' => 'y',
            'affected_files' => [], 'severity' => 'low', 'confidence' => 0.1,
        ]);

        AnalyzeClusterJob::dispatchSync($cluster->id);
        AnalyzeClusterJob::dispatchSync(999_999);

        Http::assertNothingSent();
        $this->assertSame(1, Analysis::query()->count());
    }

    #[Test]
    public function permanent_failures_mark_the_cluster(): void
    {
        Http::fake(['*' => $this->anthropicError(401, 'authentication_error', 'invalid x-api-key')]);
        $cluster = $this->makeCluster();

        AnalyzeClusterJob::dispatchSync($cluster->id);

        $cluster->refresh();
        $this->assertNotNull($cluster->analysis_failed_at);
        $this->assertStringContainsString('invalid x-api-key', (string) $cluster->analysis_error);
    }

    #[Test]
    public function retryable_failures_are_rethrown_for_the_queue(): void
    {
        Http::fake(['*' => $this->anthropicError(529, 'overloaded_error')]);
        $cluster = $this->makeCluster();

        try {
            (new AnalyzeClusterJob($cluster->id))->handle(resolve(ClusterAnalyzer::class));
            $this->fail('Expected an exception.');
        } catch (LlmRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
        }

        // The queue retries later; the cluster is not marked as failed yet.
        $this->assertNull($cluster->fresh()?->analysis_failed_at);
    }

    #[Test]
    public function the_failed_hook_records_the_error(): void
    {
        $cluster = $this->makeCluster();

        (new AnalyzeClusterJob($cluster->id))->failed(new RuntimeException('Worker timed out'));

        $this->assertSame('Worker timed out', $cluster->fresh()?->analysis_error);
    }

    #[Test]
    public function it_is_unique_per_cluster_and_sized_for_slow_requests(): void
    {
        $job = new AnalyzeClusterJob(42, requestTimeout: 300);

        $this->assertSame('42', $job->uniqueId());
        $this->assertSame(930, $job->timeout);
        $this->assertSame(3, $job->tries);
    }
}
