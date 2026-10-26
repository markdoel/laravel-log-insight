<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

use Throwable;

class LlmRequestFailed extends LogInsightException
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
        return new static("No API key configured for the [{$provider}] LLM provider.", retryable: false);
    }

    public static function http(int $status, string $type, string $message): static
    {
        // 408, 409, 429 and 5xx (including 529 overloaded) are worth retrying.
        $retryable = in_array($status, [408, 409, 429], true) || $status >= 500;

        return new static("LLM request failed with HTTP {$status} ({$type}): {$message}", $retryable, $status);
    }

    public static function connection(Throwable $previous): static
    {
        return new static('Could not reach the LLM provider: '.$previous->getMessage(), retryable: true, previous: $previous);
    }

    public static function refused(?string $category): static
    {
        return new static('The model declined to answer'.($category === null ? '.' : " (category: {$category})."), retryable: false);
    }

    public static function unexpectedResponse(string $reason): static
    {
        return new static("Unexpected response from the LLM provider: {$reason}", retryable: true);
    }
}
