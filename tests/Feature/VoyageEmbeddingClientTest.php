<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Embeddings\VoyageEmbeddingClient;
use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class VoyageEmbeddingClientTest extends TestCase
{
    use FakesVoyage;

    #[Test]
    public function it_sends_an_embeddings_request(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageResponse([[0.1, 0.2], [0.3, 0.4]])]);

        $this->client()->embed(['first text', 'second text']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://ai.mongodb.com/v1/embeddings', $request->url());
            $this->assertSame('Bearer secret-key', $request->header('Authorization')[0] ?? null);
            $this->assertSame([
                'input' => ['first text', 'second text'],
                'model' => 'voyage-4',
                'input_type' => 'document',
            ], $request->data());

            return true;
        });
    }

    #[Test]
    public function it_returns_the_vectors_in_input_order(): void
    {
        Http::fake([$this->voyageUrl() => Http::response(['object' => 'list', 'data' => [
            ['object' => 'embedding', 'embedding' => [0.3, 4], 'index' => 1],
            ['object' => 'embedding', 'embedding' => [0.1, 0.2], 'index' => 0],
        ], 'model' => 'voyage-4', 'usage' => ['total_tokens' => 4]])]);

        $this->assertSame([[0.1, 0.2], [0.3, 4.0]], $this->client()->embed(['a', 'b']));
    }

    #[Test]
    public function model_and_base_url_are_configurable(): void
    {
        Http::fake(['https://api.voyageai.com/v1/embeddings' => $this->voyageResponse([[1.0]], 'voyage-4-lite')]);

        $client = new VoyageEmbeddingClient(resolve(Factory::class), 'secret-key', 'voyage-4-lite', 'https://api.voyageai.com/');
        $client->embed(['text']);

        $this->assertSame('voyage-4-lite', $client->model());
        $this->assertSame('voyage-4-lite', $this->sentVoyageBody()['model'] ?? null);
    }

    #[Test]
    public function it_needs_an_api_key(): void
    {
        Http::fake();
        $client = new VoyageEmbeddingClient(resolve(Factory::class), null);

        $this->assertFalse($client->isConfigured());

        try {
            $client->embed(['text']);
            $this->fail('Expected an exception.');
        } catch (EmbeddingRequestFailed $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('[voyage]', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function no_texts_send_no_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->embed([]));
        Http::assertNothingSent();
    }

    #[Test]
    public function it_retries_rate_limits_and_server_errors(): void
    {
        Http::fake([$this->voyageUrl() => Http::sequence()
            ->pushResponse($this->voyageError(429, 'Rate limit exceeded'))
            ->pushResponse($this->voyageError(503, 'Service unavailable'))
            ->pushResponse($this->voyageResponse([[0.5, 0.5]]))]);

        $this->assertSame([[0.5, 0.5]], $this->client()->embed(['text']));
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_waits_as_long_as_retry_after_says(): void
    {
        Http::fake([$this->voyageUrl() => Http::sequence()
            ->push(['detail' => 'slow down'], 429, ['retry-after' => '3'])
            ->pushResponse($this->voyageResponse([[1.0]]))]);

        (new VoyageEmbeddingClient(resolve(Factory::class), 'secret-key'))->embed(['text']);

        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::usleep(3_000_000)]);
    }

    #[Test]
    public function it_gives_up_after_the_last_retry(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageError(500, 'Internal error')]);

        try {
            $this->client()->embed(['text']);
            $this->fail('Expected an exception.');
        } catch (EmbeddingRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame(500, $exception->status);
            $this->assertStringContainsString('Internal error', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function client_errors_are_not_retried(): void
    {
        Http::fake([$this->voyageUrl() => $this->voyageError(401, 'Provided API key is invalid.')]);

        try {
            $this->client()->embed(['text']);
            $this->fail('Expected an exception.');
        } catch (EmbeddingRequestFailed $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertSame(401, $exception->status);
            $this->assertStringContainsString('Provided API key is invalid.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function other_error_shapes_fall_back_to_the_reason(): void
    {
        Http::fake([$this->voyageUrl() => Http::response('', 400)]);

        $this->expectException(EmbeddingRequestFailed::class);
        $this->expectExceptionMessage('HTTP 400: Bad Request');

        $this->client()->embed(['text']);
    }

    #[Test]
    public function connection_errors_are_retryable(): void
    {
        Http::fake([$this->voyageUrl() => Http::failedConnection()]);

        try {
            $this->client()->embed(['text']);
            $this->fail('Expected an exception.');
        } catch (EmbeddingRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertStringContainsString('Could not reach the embedding provider', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unexpectedResponses(): iterable
    {
        yield 'no data' => [['object' => 'list'], 'missing data'];
        yield 'no embedding' => [['data' => [['index' => 0]]], 'missing embedding'];
        yield 'empty embedding' => [['data' => [['embedding' => [], 'index' => 0]]], 'missing embedding'];
        yield 'not numeric' => [['data' => [['embedding' => [0.1, 'x'], 'index' => 0]]], 'non-numeric'];
        yield 'too few' => [['data' => []], 'expected 1 embeddings, got 0'];
        yield 'wrong index' => [['data' => [['embedding' => [0.1], 'index' => 3]]], 'expected 1 embeddings, got 1'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('unexpectedResponses')]
    public function unexpected_responses_are_rejected(array $body, string $reason): void
    {
        Http::fake([$this->voyageUrl() => Http::response($body)]);

        try {
            $this->client()->embed(['text']);
            $this->fail('Expected an exception.');
        } catch (EmbeddingRequestFailed $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    #[Test]
    public function it_is_bound_from_the_config(): void
    {
        config()->set('log-insight.similarity.api_key', 'configured-key');
        config()->set('log-insight.similarity.model', 'voyage-4-large');

        $client = resolve(EmbeddingClient::class);

        $this->assertInstanceOf(VoyageEmbeddingClient::class, $client);
        $this->assertTrue($client->isConfigured());
        $this->assertSame('voyage', $client->provider());
        $this->assertSame('voyage-4-large', $client->model());
    }

    private function client(): VoyageEmbeddingClient
    {
        return new VoyageEmbeddingClient(
            http: resolve(Factory::class),
            apiKey: 'secret-key',
            retryDelays: [10, 20],
        );
    }
}
