<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Which failed requests to external APIs are retried, and how long to wait.
 *
 * Connection errors, 408, 409, 429 and 5xx are retried. A numeric
 * retry-after header wins over the configured delay, up to a minute.
 */
final readonly class RetryPolicy
{
    private const MAX_RETRY_AFTER_MS = 60_000;

    /**
     * @param  list<int>  $delays  milliseconds to wait before each retry
     */
    public function __construct(
        public array $delays = [2_000, 10_000],
    ) {}

    /**
     * Total attempts, the first one included.
     */
    public function attempts(): int
    {
        return count($this->delays) + 1;
    }

    public static function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 409, 429], true) || $status >= 500;
    }

    public function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return self::isRetryableStatus($exception->response->status());
        }

        return false;
    }

    /**
     * Milliseconds to wait before the given retry; honours retry-after.
     */
    public function delay(int $attempt, mixed $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('retry-after');

            if (is_numeric($retryAfter)) {
                return min((int) ((float) $retryAfter * 1000), self::MAX_RETRY_AFTER_MS);
            }
        }

        return $this->delays[$attempt - 1] ?? 0;
    }
}
