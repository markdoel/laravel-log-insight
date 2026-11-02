<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Reporting\ClusterDetailReport;
use MarkusDoell\LogInsight\Similarity\SimilarCluster;
use MarkusDoell\LogInsight\Similarity\SimilarClusters;

final class SimilarCommand extends Command
{
    protected $signature = 'log-insight:similar
        {cluster : ID of the cluster, as shown by log-insight:report}
        {--limit= : Number of similar clusters to show (default: log-insight.similarity.limit)}
        {--threshold= : Minimum cosine similarity from 0 to 1 (default: log-insight.similarity.threshold)}
        {--json : Print the result as JSON}';

    protected $description = 'Show clusters with other fingerprints that are similar to the given cluster';

    public function handle(SimilarClusters $similarClusters, ClusterDetailReport $details): int
    {
        if (! $similarClusters->isEnabled()) {
            $this->components->error('Similar clusters are disabled. Set LOG_INSIGHT_SIMILARITY_ENABLED=true and VOYAGE_API_KEY.');

            return self::FAILURE;
        }

        try {
            $clusterId = $this->clusterId();
            $limit = $this->limit() ?? $similarClusters->limit();
            $threshold = $this->threshold() ?? $similarClusters->threshold();

            $cluster = $details->cluster($clusterId);

            if ($cluster === null) {
                $this->components->error("Cluster [{$clusterId}] does not exist.");

                return self::FAILURE;
            }

            $similar = $similarClusters->forCluster($clusterId, $limit, $threshold);
        } catch (LogInsightException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'cluster' => [
                    'id' => $cluster->id,
                    'exception_class' => $cluster->exception_class,
                    'level' => $cluster->level->value,
                    'message' => $cluster->message,
                    'location' => $cluster->app_frame,
                ],
                'model' => $similarClusters->model(),
                'threshold' => $threshold,
                'limit' => $limit,
                'similar' => $similar === null ? null : array_map(static fn (SimilarCluster $match): array => $match->toArray(), $similar),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Cluster %d: %s', $cluster->id, $this->errorLabel($cluster).' – '.Str::limit($cluster->message, 80)));

        if ($similar === null) {
            $this->components->warn('This cluster has no embedding yet. EmbedClusterJob creates it once a queue worker runs; for clusters stored before similar clusters were enabled, run log-insight:embed.');

            return self::SUCCESS;
        }

        if ($similar === []) {
            $this->components->info(sprintf('No other cluster has a similarity of at least %s.', $this->percent($threshold)));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Similarity', 'Count', 'Error', 'Message', 'Location', 'Status'],
            array_map(fn (SimilarCluster $match): array => [
                $match->cluster->id,
                $this->percent($match->similarity),
                $match->cluster->occurrence_count,
                $this->errorLabel($match->cluster),
                Str::limit($match->cluster->message, 50),
                $match->cluster->app_frame ?? '-',
                $match->status(),
            ], $similar),
        );

        return self::SUCCESS;
    }

    private function errorLabel(Cluster $cluster): string
    {
        return $cluster->exception_class === null ? strtoupper($cluster->level->value) : class_basename($cluster->exception_class);
    }

    private function percent(float $similarity): string
    {
        return rtrim(rtrim(number_format($similarity * 100, 1), '0'), '.').'%';
    }

    private function clusterId(): int
    {
        // A string from the console, but callers of Artisan::call() may pass an int.
        $value = (string) $this->argument('cluster');

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new LogInsightException('The cluster argument must be a positive whole number.');
        }

        return (int) $value;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new LogInsightException('The --limit option must be a positive whole number.');
        }

        return (int) $value;
    }

    private function threshold(): ?float
    {
        $value = $this->option('threshold');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value <= 0.0 || (float) $value > 1.0) {
            throw new LogInsightException('The --threshold option must be a number greater than 0 and at most 1.');
        }

        return (float) $value;
    }
}
