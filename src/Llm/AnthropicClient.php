<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use Throwable;

/**
 * Anthropic Messages API over Laravel's HTTP client.
 *
 * The answer is constrained with structured outputs (output_config.format),
 * so the model returns JSON that matches the request's schema.
 */
final readonly class AnthropicClient implements LlmClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const MAX_RETRY_AFTER_MS = 60_000;

    /**
     * @param  string|null  $effort  low, medium, high, xhigh or max; null keeps the model default
     * @param  bool  $fallbacks  let the API retry declined requests on Anthropic's recommended fallback model
     * @param  list<int>  $retryDelays  milliseconds to wait before each retry of a retryable failure
     */
    public function __construct(
        private Factory $http,
        private ?string $apiKey,
        private string $model = 'claude-opus-5-5',
        private string $baseUrl = 'https://api.anthropic.com',
        private string $apiVersion = '2026-10-21',
        private int $timeout = 300,
        private int $maxTokens = 16000,
        private ?string $effort = 'high',
        private bool $fallbacks = true,
        private array $retryDelays = [2_000, 10_000],
    ) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        if (! $this->isConfigured()) {
            throw LlmRequestFailed::missingApiKey($this->provider());
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders($this->headers())
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->retry(
                    count($this->retryDelays) + 1,
                    fn (int $attempt, mixed $exception): int => $this->retryDelay($attempt, $exception),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->post('/v1/messages', $this->body($request));
        } catch (ConnectionException $exception) {
            throw LlmRequestFailed::connection($exception);
        }

        if ($response->failed()) {
            throw LlmRequestFailed::http(
                $response->status(),
                $this->errorField($response, 'type') ?? 'unknown_error',
                $this->errorField($response, 'message') ?? $response->reason(),
            );
        }

        return $this->toResponse($response);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'x-api-key' => (string) $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ];

        if ($this->fallbacks) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(LlmRequest $request): array
    {
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $request->schema]];

        if ($this->effort !== null && $this->effort !== '') {
            $outputConfig['effort'] = $this->effort;
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
            'output_config' => $outputConfig,
        ];

        if ($this->fallbacks) {
            $body['fallbacks'] = 'default';
        }

        return $body;
    }

    private function toResponse(Response $response): LlmResponse
    {
        $data = $response->json();

        if (! is_array($data) || ! is_array($data['content'] ?? null)) {
            throw LlmRequestFailed::unexpectedResponse('missing content');
        }

        $stopReason = is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : null;

        if ($stopReason === 'refusal') {
            $details = is_array($data['stop_details'] ?? null) ? $data['stop_details'] : [];

            throw LlmRequestFailed::refused(is_string($details['category'] ?? null) ? $details['category'] : null);
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new LlmResponse(
            text: $this->text($data['content']),
            model: is_string($data['model'] ?? null) ? $data['model'] : $this->model,
            stopReason: $stopReason,
            inputTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0,
            outputTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
            raw: $data,
        );
    }

    /**
     * Joins the text blocks of the answer. Thinking blocks are skipped; after
     * a server-side fallback only the blocks of the final model count.
     *
     * @param  array<array-key, mixed>  $content
     */
    private function text(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'fallback') {
                $text = '';
            } elseif (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return in_array($status, [408, 409, 429], true) || $status >= 500;
        }

        return false;
    }

    /**
     * Honours the retry-after header of rate limit responses.
     */
    private function retryDelay(int $attempt, mixed $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('retry-after');

            if (is_numeric($retryAfter)) {
                return min((int) ((float) $retryAfter * 1000), self::MAX_RETRY_AFTER_MS);
            }
        }

        return $this->retryDelays[$attempt - 1] ?? 0;
    }

    private function errorField(Response $response, string $field): ?string
    {
        $value = $response->json('error.'.$field);

        return is_string($value) ? $value : null;
    }
}
