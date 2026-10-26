<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Clustering;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Data\Fingerprint;
use MarkusDoell\LogInsight\Data\LogEntry;

/**
 * All entries of one analysis run that share a fingerprint.
 */
final class DetectedCluster
{
    /** @var list<DetectedOccurrence> */
    private array $occurrences = [];

    private LogEntry $representative;

    private CarbonImmutable $firstSeen;

    private CarbonImmutable $lastSeen;

    public function __construct(
        public readonly Fingerprint $fingerprint,
        LogEntry $entry,
    ) {
        $this->representative = $entry;
        $this->firstSeen = $entry->timestamp;
        $this->lastSeen = $entry->timestamp;
    }

    public function record(LogEntry $entry, string $occurrenceHash): void
    {
        $this->occurrences[] = new DetectedOccurrence(
            hash: $occurrenceHash,
            occurredAt: $entry->timestamp,
            level: $entry->level,
            channel: $entry->channel,
            message: $entry->message,
        );

        if ($entry->timestamp->lessThan($this->firstSeen)) {
            $this->firstSeen = $entry->timestamp;
        }

        // The most recent entry represents the cluster: its stack trace
        // reflects the current state of the code.
        if ($entry->timestamp->greaterThanOrEqualTo($this->lastSeen)) {
            $this->lastSeen = $entry->timestamp;
            $this->representative = $entry;
        }
    }

    public function representative(): LogEntry
    {
        return $this->representative;
    }

    /**
     * @return list<DetectedOccurrence>
     */
    public function occurrences(): array
    {
        return $this->occurrences;
    }

    public function count(): int
    {
        return count($this->occurrences);
    }

    public function firstSeen(): CarbonImmutable
    {
        return $this->firstSeen;
    }

    public function lastSeen(): CarbonImmutable
    {
        return $this->lastSeen;
    }
}
