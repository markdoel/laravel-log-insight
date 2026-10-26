<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Data;

use Carbon\CarbonImmutable;

/**
 * One parsed log record.
 *
 * For entries that carry an exception, $message, $file and $line describe
 * the exception (not the log call), and $frames holds its stack trace.
 */
final readonly class LogEntry
{
    /**
     * @param  list<StackFrame>  $frames
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        public CarbonImmutable $timestamp,
        public string $channel,
        public LogLevel $level,
        public string $message,
        public ?string $exceptionClass = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $stackTrace = null,
        public array $frames = [],
        public array $context = [],
    ) {}

    public function hasException(): bool
    {
        return $this->exceptionClass !== null;
    }
}
