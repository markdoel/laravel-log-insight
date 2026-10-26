<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Parsing;

use Carbon\CarbonImmutable;
use Generator;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Data\StackFrame;
use MarkusDoell\LogInsight\Exceptions\LogFileNotFound;
use Throwable;

/**
 * Parses Laravel's default Monolog line format:
 *
 *   [2026-01-31 14:03:12] production.ERROR: Message {"exception":"[object] (Class(code: 0): Message at /path/File.php:12)
 *   [stacktrace]
 *   #0 /path/Other.php(34): Foo->bar()
 *   #1 {main}
 *   "}
 */
final class MonologLogParser implements LogParser
{
    private const HEADER = '/^\[(?<datetime>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\] (?<channel>\S+)\.(?<level>[A-Z]+): ?(?<rest>.*)$/';

    private const EXCEPTION_MARKER = '"exception":"[object] (';

    private const EXCEPTION_HEAD = '/^(?<class>[^\s(]+)\(code: (?<code>[^)]*)\): (?<message>.*) at (?<file>.+?):(?<line>\d+)\)$/s';

    private const FRAME = '/^#(?<index>\d+) (?<file>.+)\((?<line>\d+)\): (?<call>.*)$/';

    private const INTERNAL_FRAME = '/^#(?<index>\d+) (?<call>\[internal function\]: .*|\{main\})$/';

    public function parseFile(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw LogFileNotFound::at($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw LogFileNotFound::at($path);
        }

        try {
            yield from $this->parse($this->readLines($handle));
        } finally {
            fclose($handle);
        }
    }

    public function parse(iterable $lines): Generator
    {
        /** @var array{datetime: string, channel: string, level: LogLevel, lines: list<string>}|null $pending */
        $pending = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            $header = $this->matchHeader($line);

            if ($header !== null) {
                if ($pending !== null && ($entry = $this->build($pending)) !== null) {
                    yield $entry;
                }

                $pending = $header;

                continue;
            }

            // Lines before the first header (e.g. a truncated file) are ignored.
            if ($pending !== null) {
                $pending['lines'][] = $line;
            }
        }

        if ($pending !== null && ($entry = $this->build($pending)) !== null) {
            yield $entry;
        }
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function readLines($handle): Generator
    {
        while (($line = fgets($handle)) !== false) {
            yield $line;
        }
    }

    /**
     * @return array{datetime: string, channel: string, level: LogLevel, lines: list<string>}|null
     */
    private function matchHeader(string $line): ?array
    {
        if (! str_starts_with($line, '[') || preg_match(self::HEADER, $line, $match) !== 1) {
            return null;
        }

        $level = LogLevel::fromName($match['level']);

        if ($level === null) {
            return null;
        }

        return [
            'datetime' => $match['datetime'],
            'channel' => $match['channel'],
            'level' => $level,
            'lines' => [$match['rest']],
        ];
    }

    /**
     * @param  array{datetime: string, channel: string, level: LogLevel, lines: list<string>}  $pending
     */
    private function build(array $pending): ?LogEntry
    {
        try {
            $timestamp = CarbonImmutable::parse($pending['datetime']);
        } catch (Throwable) {
            return null;
        }

        $body = rtrim(implode("\n", $pending['lines']));

        $markerPosition = strpos($body, self::EXCEPTION_MARKER);

        if ($markerPosition !== false) {
            $entry = $this->buildExceptionEntry($timestamp, $pending['channel'], $pending['level'], $body, $markerPosition);

            if ($entry !== null) {
                return $entry;
            }
        }

        [$message, $context] = $this->splitContext($body);

        return new LogEntry(
            timestamp: $timestamp,
            channel: $pending['channel'],
            level: $pending['level'],
            message: $message,
            context: $context,
        );
    }

    private function buildExceptionEntry(
        CarbonImmutable $timestamp,
        string $channel,
        LogLevel $level,
        string $body,
        int $markerPosition,
    ): ?LogEntry {
        $exception = substr($body, $markerPosition + strlen(self::EXCEPTION_MARKER));
        $exception = (string) preg_replace('/"\}\s*(?:\[\])?\s*$/', '', $exception);

        $traceStart = strpos($exception, "\n[stacktrace]");
        $head = rtrim($traceStart === false ? $exception : substr($exception, 0, $traceStart));

        if (preg_match(self::EXCEPTION_HEAD, $head, $match) !== 1) {
            return null;
        }

        $stackTrace = $traceStart === false
            ? null
            : $this->unescape(trim(substr($exception, $traceStart + strlen("\n[stacktrace]"))));

        return new LogEntry(
            timestamp: $timestamp,
            channel: $channel,
            level: $level,
            message: $this->unescape($match['message']),
            exceptionClass: $this->unescape($match['class']),
            file: $this->unescape($match['file']),
            line: (int) $match['line'],
            stackTrace: $stackTrace,
            frames: $stackTrace === null ? [] : $this->parseFrames($stackTrace),
        );
    }

    /**
     * Frames of the outermost exception only; "[previous exception]" blocks
     * stay part of the raw stack trace.
     *
     * @return list<StackFrame>
     */
    private function parseFrames(string $stackTrace): array
    {
        $frames = [];

        foreach (explode("\n", $stackTrace) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '[previous exception]')) {
                break;
            }

            if (preg_match(self::FRAME, $line, $match) === 1) {
                $frames[] = new StackFrame((int) $match['index'], $match['file'], (int) $match['line'], $match['call']);
            } elseif (preg_match(self::INTERNAL_FRAME, $line, $match) === 1) {
                $frames[] = new StackFrame((int) $match['index'], null, null, $match['call']);
            }
        }

        return $frames;
    }

    /**
     * Split a trailing JSON context ("message {"key":"value"}") off the message.
     *
     * @return array{string, array<array-key, mixed>}
     */
    private function splitContext(string $body): array
    {
        $body = (string) preg_replace('/ \[\]$/', '', $body);

        if (! str_ends_with($body, '}') && ! str_ends_with($body, ']')) {
            return [$body, []];
        }

        $offset = strlen($body);

        while (($position = $this->lastContextStart($body, $offset)) !== null) {
            $decoded = json_decode(substr($body, $position + 1), true);

            if (is_array($decoded)) {
                return [substr($body, 0, $position), $decoded];
            }

            $offset = $position;
        }

        return [$body, []];
    }

    private function lastContextStart(string $body, int $before): ?int
    {
        $haystack = substr($body, 0, $before);
        $positions = array_filter([strrpos($haystack, ' {'), strrpos($haystack, ' [')], is_int(...));

        return $positions === [] ? null : max($positions);
    }

    /**
     * Undo the JSON string escaping Monolog applies to the exception context.
     */
    private function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(["\\\\\/])/',
            static fn (array $match): string => $match[1],
            $value,
        );
    }
}
