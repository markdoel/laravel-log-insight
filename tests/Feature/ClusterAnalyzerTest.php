<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Ai\ClusterAnalyzer;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Exceptions\InvalidAnalysisResponse;
use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Tests\Concerns\FakesAnthropic;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterAnalyzerTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableAnthropic();
        Event::fake([AnalysisCompleted::class]);
    }

    #[Test]
    public function it_stores_the_analysis_and_fires_an_event(): void
    {
        Http::fake(['*' => $this->anthropicResponse($this->analysisPayload())]);
        $cluster = $this->makeCluster(['analysis_failed_at' => now(), 'analysis_error' => 'earlier failure']);

        $analysis = resolve(ClusterAnalyzer::class)->analyze($cluster);

        $this->assertSame('anthropic', $analysis->provider);
        $this->assertSame('claude-opus-5-5', $analysis->model);
        $this->assertSame('The order was deleted before the customer opened the link.', $analysis->root_cause);
        $analysis->refresh();
        $this->assertSame(['app/Http/Controllers/OrderController.php'], $analysis->affected_files);
        $this->assertSame(Severity::Medium, $analysis->severity);
        $this->assertSame(0.82, $analysis->confidence);
        $this->assertSame(1200, $analysis->input_tokens);
        $this->assertSame(350, $analysis->output_tokens);
        $this->assertSame('msg_test', $analysis->raw_response['id'] ?? null);

        $this->assertNull($cluster->fresh()?->analysis_failed_at);
        $this->assertNull($cluster->fresh()?->analysis_error);

        Event::assertDispatched(AnalysisCompleted::class, fn (AnalysisCompleted $event): bool => $event->analysis->is($analysis));
    }

    #[Test]
    public function the_request_contains_the_redacted_cluster_context(): void
    {
        Http::fake(['*' => $this->anthropicResponse($this->analysisPayload())]);

        resolve(ClusterAnalyzer::class)->analyze($this->makeCluster(['sample_message' => 'Order of jane@example.com not found']));

        $content = $this->sentMessages()[0]['content'];

        $this->assertStringContainsString('Order of [REDACTED_EMAIL] not found', $content);
        $this->assertStringNotContainsString('jane@example.com', $content);
    }

    #[Test]
    public function an_invalid_answer_is_retried_once_with_a_correction(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushResponse($this->anthropicResponse('I think the order was deleted.', ['usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]))
            ->pushResponse($this->anthropicResponse($this->analysisPayload(), ['usage' => ['input_tokens' => 1500, 'output_tokens' => 300]]))]);

        $analysis = resolve(ClusterAnalyzer::class)->analyze($this->makeCluster());

        $this->assertSame(2500, $analysis->input_tokens);
        $this->assertSame(400, $analysis->output_tokens);
        Http::assertSentCount(2);

        $messages = $this->sentMessages(1);
        $this->assertCount(3, $messages);
        $this->assertSame(['role' => 'assistant', 'content' => 'I think the order was deleted.'], $messages[1]);
        $this->assertSame('user', $messages[2]['role']);
        $this->assertStringContainsString('not valid JSON', $messages[2]['content']);
    }

    #[Test]
    public function a_second_invalid_answer_gives_up_without_storing(): void
    {
        Http::fake(['*' => $this->anthropicResponse('{"root_cause":"x"}')]);

        try {
            resolve(ClusterAnalyzer::class)->analyze($this->makeCluster());
            $this->fail('Expected an exception.');
        } catch (InvalidAnalysisResponse) {
            // expected
        }

        Http::assertSentCount(2);
        $this->assertSame(0, Analysis::query()->count());
        Event::assertNotDispatched(AnalysisCompleted::class);
    }

    #[Test]
    public function a_truncated_answer_asks_for_a_shorter_one(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushResponse($this->anthropicResponse('{"root_cause": "The ord', ['stop_reason' => 'max_tokens']))
            ->pushResponse($this->anthropicResponse($this->analysisPayload()))]);

        resolve(ClusterAnalyzer::class)->analyze($this->makeCluster());

        $this->assertStringContainsString('cut off', $this->sentMessages(1)[2]['content'] ?? '');
    }

    #[Test]
    public function already_analyzed_clusters_are_never_sent_again(): void
    {
        Http::fake();
        $cluster = $this->makeCluster();
        $existing = $cluster->analyses()->create([
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'root_cause' => 'Known',
            'suggested_fix' => 'Known fix',
            'affected_files' => [],
            'severity' => Severity::Low,
            'confidence' => 0.5,
        ]);

        $analysis = resolve(ClusterAnalyzer::class)->analyze($cluster);

        $this->assertTrue($analysis->is($existing));
        Http::assertNothingSent();
    }

    #[Test]
    public function provider_errors_propagate(): void
    {
        Http::fake(['*' => $this->anthropicError(400, 'invalid_request_error', 'bad request')]);

        $this->expectException(LlmRequestFailed::class);

        resolve(ClusterAnalyzer::class)->analyze($this->makeCluster());
    }
}
