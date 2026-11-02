<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use MarkusDoell\LogInsight\Contracts\EmbeddingClient;
use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;
use MarkusDoell\LogInsight\Http\RetryPolicy;
use Throwable;

/**
 * Voyage AI text embeddings over Laravel's HTTP client.
 *
 * POST {base_url}/v1/embeddings with a bearer token. The default base URL is
 * the Atlas Embedding and Reranking API, which takes model API keys created
 * in MongoDB Atlas. Keys from the Voyage AI platform use
 * https://api.voyageai.com instead.
 *
 * Every text is embedded as a "document": clusters are compared with each
 * other, so both sides of a comparison get the same prompt.
 */
final readonly class VoyageEmbeddingClient implements EmbeddingClient
{
    public const DEFAULT_BASE_URL = 'https://ai.mongodb.com';

    public const DEFAULT_MODEL = 'voyage-4';

    /**
     * @param  list<int>  $retryDelays  milliseconds to wait before each retry of a retryable failure
     */
    public function __construct(
        private Factory $http,
        private ?string $apiKey,
        private string $model = self::DEFAULT_MODEL,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeout = 30,
        private array $retryDelays = [2_000, 10_000],
    ) {}

    public function provider(): string
    {
        return 'voyage';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            throw EmbeddingRequestFailed::missingApiKey($this->provider());
        }

        $retries = new RetryPolicy($this->retryDelays);

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->retry(
                    $retries->attempts(),
                    static fn (int $attempt, mixed $exception): int => $retries->delay($attempt, $exception),
                    static fn (Throwable $exception): bool => $retries->shouldRetry($exception),
                    throw: false,
                )
                ->post('/v1/embeddings', [
                    'input' => $texts,
                    'model' => $this->model,
                    'input_type' => 'document',
                ]);
        } catch (ConnectionException $exception) {
            throw EmbeddingRequestFailed::connection($exception);
        }

        if ($response->failed()) {
            throw EmbeddingRequestFailed::http($response->status(), $this->errorMessage($response));
        }

        return $this->vectors($response, count($texts));
    }

    /**
     * Reads data[].embedding, ordered by data[].index.
     *
     * @return list<list<float>>
     */
    private function vectors(Response $response, int $expected): array
    {
        $data = $response->json('data');

        if (! is_array($data)) {
            throw EmbeddingRequestFailed::unexpectedResponse('missing data');
        }

        $vectors = [];

        foreach ($data as $position => $item) {
            if (! is_array($item) || ! is_array($item['embedding'] ?? null) || $item['embedding'] === []) {
                throw EmbeddingRequestFailed::unexpectedResponse('missing embedding');
            }

            $index = is_int($item['index'] ?? null) ? $item['index'] : $position;
            $vector = [];

            foreach ($item['embedding'] as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    throw EmbeddingRequestFailed::unexpectedResponse('embedding contains a non-numeric value');
                }

                $vector[] = (float) $value;
            }

            $vectors[$index] = $vector;
        }

        ksort($vectors);

        if (count($vectors) !== $expected || array_keys($vectors) !== range(0, $expected - 1)) {
            throw EmbeddingRequestFailed::unexpectedResponse(sprintf('expected %d embeddings, got %d', $expected, count($vectors)));
        }

        return array_values($vectors);
    }

    /**
     * The error body is read defensively: "detail", then the usual other shapes.
     */
    private function errorMessage(Response $response): string
    {
        foreach (['detail', 'error.message', 'message', 'error'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $response->reason();
    }
}
