<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Ai\ClusterAnalyzer;
use MarkusDoell\LogInsight\Exceptions\InvalidAnalysisResponse;
use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use MarkusDoell\LogInsight\Models\Cluster;
use Throwable;

/**
 * Asks the AI provider for a root cause analysis of one cluster.
 */
final class AnalyzeClusterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $clusterId,
        int $requestTimeout = 300,
    ) {
        // Leave room for the provider's own retries inside one attempt.
        $this->timeout = $requestTimeout * 3 + 30;
    }

    public function uniqueId(): string
    {
        return (string) $this->clusterId;
    }

    public function handle(ClusterAnalyzer $analyzer): void
    {
        $cluster = Cluster::query()->find($this->clusterId);

        // Deleted in the meantime, or analyzed by an earlier attempt.
        if ($cluster === null || $cluster->analyses()->exists()) {
            return;
        }

        try {
            $analyzer->analyze($cluster);
        } catch (LlmRequestFailed $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (InvalidAnalysisResponse $exception) {
            // Already retried once with a correction; another attempt is unlikely to help.
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cluster::query()->whereKey($this->clusterId)->update([
            'analysis_failed_at' => CarbonImmutable::now(),
            'analysis_error' => Str::limit($exception?->getMessage() ?? 'Unknown error', 1000),
        ]);
    }
}
