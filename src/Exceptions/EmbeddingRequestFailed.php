<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

use MarkusDoell\LogInsight\Http\RetryPolicy;
use Throwable;

class EmbeddingRequestFailed extends LogInsightException
{
    final public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function missingApiKey(string $provider): static
    {
        return new static("No API key configured for the [{$provider}] embedding provider.", retryable: false);
    }

    public static function http(int $status, string $message): static
    {
        return new static("Embedding request failed with HTTP {$status}: {$message}", RetryPolicy::isRetryableStatus($status), $status);
    }

    public static function connection(Throwable $previous): static
    {
        return new static('Could not reach the embedding provider: '.$previous->getMessage(), retryable: true, previous: $previous);
    }

    public static function unexpectedResponse(string $reason): static
    {
        return new static("Unexpected response from the embedding provider: {$reason}", retryable: true);
    }
}
