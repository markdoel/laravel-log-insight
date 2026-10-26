<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Clustering;

use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\LogEntry;

/**
 * Groups the entries of one analysis run by fingerprint.
 */
final class ClusterAggregator
{
    /** @var array<string, DetectedCluster> */
    private array $clusters = [];

    /** @var array<string, int> */
    private array $duplicates = [];

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
    ) {}

    public function add(LogEntry $entry): void
    {
        $fingerprint = $this->fingerprinter->fingerprint($entry);

        $cluster = $this->clusters[$fingerprint->hash] ??= new DetectedCluster($fingerprint, $entry);
        $cluster->record($entry, $this->occurrenceHash($fingerprint->hash, $entry));
    }

    /**
     * Clusters ordered by number of occurrences, most frequent first.
     *
     * @return list<DetectedCluster>
     */
    public function clusters(): array
    {
        $clusters = array_values($this->clusters);

        usort($clusters, static fn (DetectedCluster $a, DetectedCluster $b): int => [$b->count(), $b->lastSeen()] <=> [$a->count(), $a->lastSeen()]);

        return $clusters;
    }

    /**
     * Stable across runs over the same log, so re-analyzing a file does not
     * count entries twice. Identical entries within the same timestamp get a
     * sequence number to stay distinct.
     */
    private function occurrenceHash(string $fingerprint, LogEntry $entry): string
    {
        $key = sha1(implode("\0", [
            $fingerprint,
            $entry->timestamp->format('Y-m-d H:i:s.uP'),
            $entry->channel,
            $entry->level->value,
            $entry->message,
            $entry->file ?? '',
            (string) $entry->line,
        ]));

        $sequence = $this->duplicates[$key] = ($this->duplicates[$key] ?? 0) + 1;

        return sha1($key.'#'.$sequence);
    }
}
