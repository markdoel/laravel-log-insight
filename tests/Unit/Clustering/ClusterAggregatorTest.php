<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Clustering;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Clustering\ClusterAggregator;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Fingerprinting\DefaultFingerprinter;
use MarkusDoell\LogInsight\Fingerprinting\MessageNormalizer;
use MarkusDoell\LogInsight\Support\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClusterAggregatorTest extends TestCase
{
    private ClusterAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new ClusterAggregator(
            new DefaultFingerprinter(new MessageNormalizer, new AppPaths('/var/www/html', ['app/'])),
        );
    }

    #[Test]
    public function it_groups_entries_by_fingerprint_with_count_and_time_range(): void
    {
        $this->aggregator->add($this->entry('2026-10-25 10:00:00', 'Order 1 failed'));
        $this->aggregator->add($this->entry('2026-10-25 08:00:00', 'Order 2 failed'));
        $this->aggregator->add($this->entry('2026-10-25 12:00:00', 'Order 3 failed'));
        $this->aggregator->add($this->entry('2026-10-25 09:00:00', 'Disk full'));

        $clusters = $this->aggregator->clusters();

        $this->assertCount(2, $clusters);

        [$orders, $disk] = $clusters;
        $this->assertSame(3, $orders->count());
        $this->assertSame('Order {n} failed', $orders->fingerprint->normalizedMessage);
        $this->assertSame('2026-10-25 08:00:00', $orders->firstSeen()->toDateTimeString());
        $this->assertSame('2026-10-25 12:00:00', $orders->lastSeen()->toDateTimeString());
        $this->assertSame(1, $disk->count());
    }

    #[Test]
    public function it_uses_the_most_recent_entry_as_representative(): void
    {
        $this->aggregator->add($this->entry('2026-10-25 10:00:00', 'Order 1 failed'));
        $this->aggregator->add($this->entry('2026-10-25 12:00:00', 'Order 3 failed'));
        $this->aggregator->add($this->entry('2026-10-25 11:00:00', 'Order 2 failed'));

        $this->assertSame('Order 3 failed', $this->aggregator->clusters()[0]->representative()->message);
    }

    #[Test]
    public function it_sorts_clusters_by_count_then_recency(): void
    {
        $this->aggregator->add($this->entry('2026-10-25 10:00:00', 'Old single'));
        $this->aggregator->add($this->entry('2026-10-25 11:00:00', 'Recent single'));
        $this->aggregator->add($this->entry('2026-10-25 09:00:00', 'Twice'));
        $this->aggregator->add($this->entry('2026-10-25 09:01:00', 'Twice'));

        $messages = array_map(
            static fn ($cluster): string => $cluster->fingerprint->normalizedMessage,
            $this->aggregator->clusters(),
        );

        $this->assertSame(['Twice', 'Recent single', 'Old single'], $messages);
    }

    #[Test]
    public function it_gives_identical_entries_distinct_but_stable_occurrence_hashes(): void
    {
        $entry = $this->entry('2026-10-25 10:00:00', 'Order 1 failed');

        $this->aggregator->add($entry);
        $this->aggregator->add($entry);

        $hashes = array_map(static fn ($occurrence): string => $occurrence->hash, $this->aggregator->clusters()[0]->occurrences());

        $this->assertCount(2, array_unique($hashes));

        $again = new ClusterAggregator(new DefaultFingerprinter(new MessageNormalizer, new AppPaths('/var/www/html', ['app/'])));
        $again->add($entry);
        $again->add($entry);

        $this->assertSame($hashes, array_map(static fn ($occurrence): string => $occurrence->hash, $again->clusters()[0]->occurrences()));
    }

    private function entry(string $time, string $message): LogEntry
    {
        return new LogEntry(
            timestamp: CarbonImmutable::parse($time),
            channel: 'production',
            level: LogLevel::Error,
            message: $message,
        );
    }
}
