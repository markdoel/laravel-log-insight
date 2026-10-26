<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Analysis;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\LogReader;

/**
 * Orchestrates one analysis run: locate files, read entries and summarise them.
 */
final readonly class LogAnalyzer
{
    public function __construct(
        private LogFileLocator $locator,
        private LogReader $reader,
    ) {}

    public function analyze(?string $file = null, ?CarbonImmutable $since = null, bool $dryRun = false): AnalysisResult
    {
        $files = $this->locator->locate($file, $since);

        $entries = 0;
        $exceptions = 0;
        $levels = [];

        foreach ($this->reader->read($files, $since) as $entry) {
            $entries++;
            $levels[$entry->level->value] = ($levels[$entry->level->value] ?? 0) + 1;

            if ($entry->hasException()) {
                $exceptions++;
            }
        }

        return new AnalysisResult($files, $entries, $exceptions, $levels, $dryRun);
    }
}
