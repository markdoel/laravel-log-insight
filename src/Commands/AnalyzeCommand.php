<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Analysis\AnalysisResult;
use MarkusDoell\LogInsight\Analysis\ClusterSummary;
use MarkusDoell\LogInsight\Analysis\LogAnalyzer;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Support\Since;

final class AnalyzeCommand extends Command
{
    private const TABLE_LIMIT = 10;

    protected $signature = 'log-insight:analyze
        {--file= : Analyze a single log file instead of the configured log directory}
        {--since= : Only consider entries newer than this, e.g. 30m, 24h, 7d or 2026-01-31}
        {--dry-run : Parse and cluster without storing anything or calling the AI provider}';

    protected $description = 'Parse the Laravel logs, cluster recurring errors and queue them for AI analysis';

    public function handle(LogAnalyzer $analyzer): int
    {
        try {
            $since = $this->stringOption('since');
            $result = $analyzer->analyze(
                file: $this->stringOption('file'),
                since: $since === null ? null : Since::parse($since),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (LogInsightException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->dryRun) {
            $this->components->warn('Dry run: nothing is stored and no AI provider is called.');
        }

        if ($result->files === []) {
            $this->components->info('No log files found.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Parsed %d %s (%d with exception) from %d %s.',
            $result->entries,
            Str::plural('entry', $result->entries),
            $result->exceptions,
            count($result->files),
            Str::plural('file', count($result->files)),
        ));

        $this->renderClusters($result);
        $this->renderAiStatus($result);

        return self::SUCCESS;
    }

    private function renderAiStatus(AnalysisResult $result): void
    {
        if ($result->dryRun || $result->clusters === []) {
            return;
        }

        match ($result->aiStatus) {
            AnalysisResult::AI_DISABLED => $this->components->info('AI analysis is disabled (log-insight.analysis.enabled).'),
            AnalysisResult::AI_NOT_CONFIGURED => $this->components->warn('No API key configured, so no AI analysis was queued. Set ANTHROPIC_API_KEY to enable it.'),
            default => $this->components->info($result->queuedAnalyses === 0
                ? 'No clusters waiting for AI analysis.'
                : sprintf('Queued %d %s for AI analysis.', $result->queuedAnalyses, Str::plural('cluster', $result->queuedAnalyses))),
        };
    }

    private function renderClusters(AnalysisResult $result): void
    {
        if ($result->clusters === []) {
            $this->components->info('No errors to cluster.');

            return;
        }

        $this->components->info($result->dryRun
            ? sprintf('Found %d %s.', count($result->clusters), Str::plural('cluster', count($result->clusters)))
            : sprintf('Found %d %s (%d new).', count($result->clusters), Str::plural('cluster', count($result->clusters)), $result->newClusters()));

        $this->table(
            ['Count', 'Error', 'Message', 'Location', 'New'],
            array_map(static fn (ClusterSummary $cluster): array => [
                $cluster->occurrences,
                $cluster->exceptionClass === null ? strtoupper($cluster->level) : class_basename($cluster->exceptionClass),
                Str::limit($cluster->message, 60),
                $cluster->appFrame ?? '-',
                match ($cluster->isNew) {
                    true => 'yes',
                    false => 'no',
                    null => '-',
                },
            ], array_slice($result->clusters, 0, self::TABLE_LIMIT)),
        );

        if (count($result->clusters) > self::TABLE_LIMIT) {
            $this->line(sprintf('  … and %d more. See log-insight:report for all clusters.', count($result->clusters) - self::TABLE_LIMIT));
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
