<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Contracts;

use Generator;
use MarkusDoell\LogInsight\Data\LogEntry;

interface LogParser
{
    /**
     * Parse raw log lines into entries. Lines that continue a previous entry
     * (stack traces, multi-line messages) are merged into that entry.
     *
     * @param  iterable<string>  $lines
     * @return Generator<int, LogEntry>
     */
    public function parse(iterable $lines): Generator;

    /**
     * Stream-parse a log file without loading it into memory.
     *
     * @return Generator<int, LogEntry>
     */
    public function parseFile(string $path): Generator;
}
