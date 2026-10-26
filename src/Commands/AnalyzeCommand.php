<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Commands;

use Illuminate\Console\Command;
use MarkusDoell\LogInsight\Analysis\LogAnalyzer;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Support\Since;

final class AnalyzeCommand extends Command
{
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
            $result->entries === 1 ? 'entry' : 'entries',
            $result->exceptions,
            count($result->files),
            count($result->files) === 1 ? 'file' : 'files',
        ));

        foreach ($result->levels as $level => $count) {
            $this->components->twoColumnDetail(strtoupper($level), (string) $count);
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
