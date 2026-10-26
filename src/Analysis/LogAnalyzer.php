<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use MarkusDoell\LogInsight\Clustering\ClusterAggregator;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\LogReader;
use MarkusDoell\LogInsight\Storage\ClusterRepository;

/**
 * Orchestrates one analysis run: locate files, read entries, cluster
 * everything at or above the minimum level and store the clusters.
 */
final readonly class LogAnalyzer
{
    public function __construct(
        private LogFileLocator $locator,
        private LogReader $reader,
        private Fingerprinter $fingerprinter,
        private ClusterRepository $repository,
        private Dispatcher $events,
        private LogLevel $minLevel = LogLevel::Error,
    ) {}

    public function analyze(?string $file = null, ?CarbonImmutable $since = null, bool $dryRun = false): AnalysisResult
    {
        $files = $this->locator->locate($file, $since);
        $aggregator = new ClusterAggregator($this->fingerprinter);

        $entries = 0;
        $exceptions = 0;
        $levels = [];

        foreach ($this->reader->read($files, $since) as $entry) {
            $entries++;
            $levels[$entry->level->value] = ($levels[$entry->level->value] ?? 0) + 1;

            if ($entry->hasException()) {
                $exceptions++;
            }

            if ($entry->level->isAtLeast($this->minLevel)) {
                $aggregator->add($entry);
            }
        }

        $clusters = [];

        foreach ($aggregator->clusters() as $detected) {
            if ($dryRun) {
                $clusters[] = ClusterSummary::fromDetected($detected, null);

                continue;
            }

            $stored = $this->repository->store($detected);

            if ($stored->isNew) {
                $this->events->dispatch(new ClusterDetected($stored->cluster));
            }

            $clusters[] = ClusterSummary::fromDetected($detected, $stored->isNew);
        }

        return new AnalysisResult($files, $entries, $exceptions, $levels, $clusters, $dryRun);
    }
}
