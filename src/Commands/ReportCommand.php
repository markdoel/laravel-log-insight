<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Reporting\ClusterReport;
use MarkusDoell\LogInsight\Reporting\Report;
use MarkusDoell\LogInsight\Reporting\ReportRow;
use MarkusDoell\LogInsight\Support\Since;

final class ReportCommand extends Command
{
    protected $signature = 'log-insight:report
        {--limit=10 : Number of clusters to show}
        {--since= : Only clusters seen since then, e.g. 24h, 7d or 2026-01-31}
        {--severity= : Only analyzed clusters with at least this severity (low, medium, high, critical)}
        {--json : Print the report as JSON}';

    protected $description = 'Show the most frequent error clusters and their AI analysis';

    public function handle(ClusterReport $reports): int
    {
        try {
            $since = $this->stringOption('since');
            $report = $reports->build(
                limit: $this->limit(),
                since: $since === null ? null : Since::parse($since),
                minSeverity: $this->severity(),
            );
        } catch (LogInsightException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTable($report);

        return self::SUCCESS;
    }

    private function renderTable(Report $report): void
    {
        if ($report->rows === []) {
            $this->components->info($report->since === null && $report->minSeverity === null
                ? 'No clusters stored yet. Run log-insight:analyze first.'
                : 'No clusters match the filters.');

            return;
        }

        $this->components->info(sprintf(
            'Top %d of %d %s, most frequent first.',
            count($report->rows),
            $report->total,
            Str::plural('cluster', $report->total),
        ));

        $this->table(
            ['ID', 'Count', 'Error', 'Message', 'Location', 'Last seen', 'Severity', 'Root cause'],
            array_map(fn (ReportRow $row): array => [
                $row->cluster->id,
                $row->cluster->occurrence_count,
                $row->cluster->exception_class === null ? strtoupper($row->cluster->level->value) : class_basename($row->cluster->exception_class),
                Str::limit($row->cluster->message, 50),
                $row->cluster->app_frame ?? '-',
                $row->cluster->last_seen_at->diffForHumans(),
                $this->severityLabel($row),
                $row->analysis === null ? '-' : Str::limit($row->analysis->root_cause, 60),
            ], $report->rows),
        );

        $this->line('  Use --json for the full analysis including suggested fixes.');
    }

    private function severityLabel(ReportRow $row): string
    {
        if ($row->analysis !== null) {
            return sprintf('%s (%d%%)', $row->analysis->severity->value, (int) round($row->analysis->confidence * 100));
        }

        return $row->status();
    }

    private function limit(): int
    {
        $limit = $this->option('limit');

        if (! is_numeric($limit) || (int) $limit < 1 || (string) (int) $limit !== (string) $limit) {
            throw new LogInsightException('The --limit option must be a positive whole number.');
        }

        return (int) $limit;
    }

    private function severity(): ?Severity
    {
        $value = $this->stringOption('severity');

        if ($value === null) {
            return null;
        }

        return Severity::tryFrom(strtolower($value))
            ?? throw new LogInsightException('The --severity option must be one of: '.implode(', ', Severity::values()).'.');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
