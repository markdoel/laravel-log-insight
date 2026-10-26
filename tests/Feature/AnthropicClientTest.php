<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use MarkusDoell\LogInsight\Ai\AnalysisPrompt;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use MarkusDoell\LogInsight\Llm\AnthropicClient;
use MarkusDoell\LogInsight\Llm\LlmRequest;
use MarkusDoell\LogInsight\Tests\Concerns\FakesAnthropic;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AnthropicClientTest extends TestCase
{
    use FakesAnthropic;

    private const URL = 'https://api.anthropic.com/v1/messages';

    #[Test]
    public function it_sends_a_structured_output_request(): void
    {
        Http::fake([self::URL => $this->anthropicResponse($this->analysisPayload())]);

        $this->client()->complete($this->request());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('secret-key', $request->header('x-api-key')[0] ?? null);
            $this->assertSame('2026-10-21', $request->header('anthropic-version')[0] ?? null);
            $this->assertSame('server-side-fallback-2026-07-01', $request->header('anthropic-beta')[0] ?? null);

            $body = $request->data();
            $this->assertSame('claude-opus-5-5', $body['model']);
            $this->assertSame(16000, $body['max_tokens']);
            $this->assertSame(AnalysisPrompt::SYSTEM, $body['system']);
            $this->assertSame([['role' => 'user', 'content' => 'Analyze this.']], $body['messages']);
            $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => AnalysisPrompt::schema()], 'effort' => 'high'], $body['output_config']);
            $this->assertSame('default', $body['fallbacks']);

            // Removed in API version 2026-10-21; thinking is always on for this model.
            $this->assertArrayNotHasKey('temperature', $body);
            $this->assertArrayNotHasKey('thinking', $body);

            return true;
        });
    }

    #[Test]
    public function it_returns_text_model_and_usage(): void
    {
        Http::fake([self::URL => $this->anthropicResponse('{"ok":true}', ['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn'])]);

        $response = $this->client()->complete($this->request());

        $this->assertSame('{"ok":true}', $response->text);
        $this->assertSame('claude-opus-5-5', $response->model);
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(1200, $response->inputTokens);
        $this->assertSame(350, $response->outputTokens);
        $this->assertFalse($response->wasTruncated());
        $this->assertSame('msg_test', $response->raw['id'] ?? null);
    }

    #[Test]
    public function after_a_fallback_only_the_final_models_text_counts(): void
    {
        Http::fake([self::URL => $this->anthropicResponse('', [
            'model' => 'claude-opus-4-8',
            'content' => [
                ['type' => 'text', 'text' => '{"partial":'],
                ['type' => 'fallback'],
                ['type' => 'text', 'text' => '{"answer":'],
                ['type' => 'text', 'text' => '1}'],
            ],
        ])]);

        $response = $this->client()->complete($this->request());

        $this->assertSame('{"answer":1}', $response->text);
        $this->assertSame('claude-opus-4-8', $response->model);
    }

    #[Test]
    public function effort_and_fallbacks_can_be_turned_off(): void
    {
        Http::fake([self::URL => $this->anthropicResponse('{}')]);

        $this->client(effort: null, fallbacks: false)->complete($this->request());

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('anthropic-beta'));

        $body = $this->sentBody();
        $this->assertArrayNotHasKey('fallbacks', $body);
        $outputConfig = $body['output_config'] ?? null;
        $this->assertIsArray($outputConfig);
        $this->assertArrayNotHasKey('effort', $outputConfig);
    }

    #[Test]
    public function it_retries_rate_limits_and_overload(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->pushResponse($this->anthropicError(429, 'rate_limit_error'))
            ->pushResponse($this->anthropicError(529, 'overloaded_error'))
            ->pushResponse($this->anthropicResponse('{"ok":true}'))]);

        $response = $this->client()->complete($this->request());

        $this->assertSame('{"ok":true}', $response->text);
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_waits_as_long_as_retry_after_says(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429, ['retry-after' => '7'])
            ->pushResponse($this->anthropicResponse('{}'))]);

        (new AnthropicClient(http: resolve(Factory::class), apiKey: 'secret-key'))->complete($this->request());

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::usleep(7_000_000)]);
    }

    #[Test]
    public function it_gives_up_after_the_last_retry(): void
    {
        Http::fake([self::URL => $this->anthropicError(529, 'overloaded_error', 'Overloaded')]);

        try {
            $this->client()->complete($this->request());
            $this->fail('Expected an exception.');
        } catch (LlmRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame(529, $exception->status);
            $this->assertStringContainsString('overloaded_error', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function client_errors_are_not_retried(): void
    {
        Http::fake([self::URL => $this->anthropicError(401, 'authentication_error', 'invalid x-api-key')]);

        try {
            $this->client()->complete($this->request());
            $this->fail('Expected an exception.');
        } catch (LlmRequestFailed $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertSame(401, $exception->status);
            $this->assertStringContainsString('invalid x-api-key', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function refusals_are_permanent_failures(): void
    {
        Http::fake([self::URL => $this->anthropicResponse('', [
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null],
        ])]);

        $this->expectException(LlmRequestFailed::class);
        $this->expectExceptionMessage('category: cyber');

        $this->client()->complete($this->request());
    }

    #[Test]
    public function connection_problems_are_retryable(): void
    {
        Http::fake([self::URL => Http::failedConnection()]);

        try {
            $this->client()->complete($this->request());
            $this->fail('Expected an exception.');
        } catch (LlmRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
        }
    }

    #[Test]
    public function it_rejects_unexpected_bodies(): void
    {
        Http::fake([self::URL => Http::response(['unexpected' => true])]);

        $this->expectException(LlmRequestFailed::class);
        $this->expectExceptionMessage('missing content');

        $this->client()->complete($this->request());
    }

    #[Test]
    public function it_refuses_to_send_without_an_api_key(): void
    {
        Http::fake();

        $client = $this->client(apiKey: null);

        $this->assertFalse($client->isConfigured());

        try {
            $client->complete($this->request());
            $this->fail('Expected an exception.');
        } catch (LlmRequestFailed $exception) {
            $this->assertFalse($exception->retryable);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_container_builds_the_client_from_config(): void
    {
        config()->set('log-insight.llm.anthropic', [
            'api_key' => 'from-config',
            'model' => 'claude-sonnet-5-5',
            'base_url' => 'https://proxy.example.test/',
            'api_version' => '2026-10-21',
            'timeout' => 30,
            'max_tokens' => 4000,
            'effort' => 'low',
            'fallbacks' => true,
        ]);
        Http::fake(['https://proxy.example.test/v1/messages' => $this->anthropicResponse('{}')]);

        $client = resolve(LlmClient::class);
        $client->complete($this->request());

        $this->assertSame('anthropic', $client->provider());
        Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['from-config']);

        $body = $this->sentBody();
        $this->assertSame('claude-sonnet-5-5', $body['model'] ?? null);
        $this->assertSame(4000, $body['max_tokens'] ?? null);
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => AnalysisPrompt::schema()], 'effort' => 'low'], $body['output_config'] ?? null);
    }

    private function client(?string $apiKey = 'secret-key', ?string $effort = 'high', bool $fallbacks = true): AnthropicClient
    {
        return new AnthropicClient(
            http: resolve(Factory::class),
            apiKey: $apiKey,
            effort: $effort,
            fallbacks: $fallbacks,
            retryDelays: [0, 0],
        );
    }

    private function request(): LlmRequest
    {
        return new LlmRequest(AnalysisPrompt::SYSTEM, [['role' => 'user', 'content' => 'Analyze this.']], AnalysisPrompt::schema());
    }
}
