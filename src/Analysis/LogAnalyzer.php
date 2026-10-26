<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use MarkusDoell\LogInsight\Ai\AnalysisScheduler;
use MarkusDoell\LogInsight\Clustering\ClusterAggregator;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\LogReader;
use MarkusDoell\LogInsight\Storage\ClusterRepository;

/**
 * Orchestrates one analysis run: locate files, read entries, cluster
 * everything at or above the minimum level, store the clusters and queue
 * the ones without an analysis for the AI provider.
 */
final readonly class LogAnalyzer
{
    public function __construct(
        private LogFileLocator $locator,
        private LogReader $reader,
        private Fingerprinter $fingerprinter,
        private ClusterRepository $repository,
        private Dispatcher $events,
        private AnalysisScheduler $scheduler,
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
        $queued = 0;

        foreach ($aggregator->clusters() as $detected) {
            if ($dryRun) {
                $clusters[] = ClusterSummary::fromDetected($detected, null);

                continue;
            }

            $stored = $this->repository->store($detected);

            if ($stored->isNew) {
                $this->events->dispatch(new ClusterDetected($stored->cluster));
            }

            if ($this->scheduler->schedule($stored->cluster)) {
                $queued++;
            }

            $clusters[] = ClusterSummary::fromDetected($detected, $stored->isNew);
        }

        return new AnalysisResult(
            files: $files,
            entries: $entries,
            exceptions: $exceptions,
            levels: $levels,
            clusters: $clusters,
            dryRun: $dryRun,
            queuedAnalyses: $queued,
            aiStatus: match (true) {
                ! $this->scheduler->isEnabled() => AnalysisResult::AI_DISABLED,
                ! $this->scheduler->isConfigured() => AnalysisResult::AI_NOT_CONFIGURED,
                default => AnalysisResult::AI_READY,
            },
        );
    }
}
