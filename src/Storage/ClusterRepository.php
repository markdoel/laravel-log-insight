<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Storage;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Clustering\DetectedCluster;
use MarkusDoell\LogInsight\Clustering\DetectedOccurrence;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\Occurrence;

/**
 * Persists detected clusters. Storing the same log twice is idempotent:
 * occurrences are identified by their hash and never counted twice.
 */
final class ClusterRepository
{
    private const INSERT_CHUNK = 500;

    public function store(DetectedCluster $detected): StoredCluster
    {
        return (new Cluster)->getConnection()->transaction(function () use ($detected): StoredCluster {
            $cluster = Cluster::query()->where('fingerprint', $detected->fingerprint->hash)->lockForUpdate()->first();
            $isNew = $cluster === null;

            $cluster ??= new Cluster([
                'fingerprint' => $detected->fingerprint->hash,
                'type' => $detected->fingerprint->type,
                'exception_class' => $detected->representative()->exceptionClass,
                'message' => $detected->fingerprint->normalizedMessage,
                'app_frame' => $detected->fingerprint->appFrame,
                'occurrence_count' => 0,
                'first_seen_at' => $this->local($detected->firstSeen()),
                'last_seen_at' => $this->local($detected->lastSeen()),
            ]);

            if ($isNew || $detected->lastSeen()->greaterThanOrEqualTo($cluster->last_seen_at)) {
                $this->applyRepresentative($cluster, $detected);
            }

            if ($detected->firstSeen()->lessThan($cluster->first_seen_at)) {
                $cluster->first_seen_at = $this->local($detected->firstSeen());
            }

            $cluster->save();

            $newOccurrences = $this->insertOccurrences($cluster, $detected->occurrences());

            $cluster->occurrence_count = $cluster->occurrences()->count();
            $cluster->save();

            return new StoredCluster($cluster, $isNew, $newOccurrences);
        });
    }

    private function applyRepresentative(Cluster $cluster, DetectedCluster $detected): void
    {
        $entry = $detected->representative();

        $cluster->fill([
            'level' => $entry->level,
            'sample_message' => $entry->message,
            'file' => $entry->file,
            'line' => $entry->line,
            'stack_trace' => $entry->stackTrace,
            'last_seen_at' => $this->local($detected->lastSeen()),
        ]);
    }

    /**
     * @param  list<DetectedOccurrence>  $occurrences
     */
    private function insertOccurrences(Cluster $cluster, array $occurrences): int
    {
        $now = CarbonImmutable::now();
        $inserted = 0;

        foreach (array_chunk($occurrences, self::INSERT_CHUNK) as $chunk) {
            $rows = array_map(fn (DetectedOccurrence $occurrence): array => [
                'cluster_id' => $cluster->id,
                'hash' => $occurrence->hash,
                'occurred_at' => $this->local($occurrence->occurredAt),
                'level' => $occurrence->level->value,
                'channel' => $occurrence->channel,
                'message' => $occurrence->message,
                'created_at' => $now,
            ], $chunk);

            $inserted += Occurrence::query()->insertOrIgnore($rows);
        }

        return $inserted;
    }

    /**
     * Log timestamps may carry any offset; the database stores app time.
     */
    private function local(CarbonImmutable $timestamp): CarbonImmutable
    {
        return $timestamp->setTimezone(date_default_timezone_get());
    }
}
