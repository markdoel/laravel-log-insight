<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Parsing;

use Carbon\CarbonImmutable;
use Generator;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Data\LogEntry;

/**
 * Streams entries from several log files, optionally limited to a time window.
 */
final readonly class LogReader
{
    public function __construct(
        private LogParser $parser,
    ) {}

    /**
     * @param  list<string>  $files
     * @return Generator<int, LogEntry>
     */
    public function read(array $files, ?CarbonImmutable $since = null): Generator
    {
        foreach ($files as $file) {
            foreach ($this->parser->parseFile($file) as $entry) {
                if ($since !== null && $entry->timestamp->lessThan($since)) {
                    continue;
                }

                yield $entry;
            }
        }
    }
}
