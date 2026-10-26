<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MarkusDoell\LogInsight\Clustering\ClusterAggregator;
use MarkusDoell\LogInsight\Clustering\DetectedCluster;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Data\StackFrame;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\Occurrence;
use MarkusDoell\LogInsight\Storage\ClusterRepository;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterRepositoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_a_new_cluster_with_its_occurrences(): void
    {
        $stored = $this->repository()->store($this->detect([
            $this->entry('2026-10-25 09:00:00', 'Order 1 not found'),
            $this->entry('2026-10-25 11:00:00', 'Order 2 not found'),
        ]));

        $this->assertTrue($stored->isNew);
        $this->assertSame(2, $stored->newOccurrences);

        $cluster = Cluster::query()->sole();
        $this->assertSame('exception', $cluster->type);
        $this->assertSame(LogLevel::Error, $cluster->level);
        $this->assertSame('RuntimeException', $cluster->exception_class);
        $this->assertSame('Order {n} not found', $cluster->message);
        $this->assertSame('Order 2 not found', $cluster->sample_message);
        $this->assertSame('app/Services/OrderService.php:17', $cluster->app_frame);
        $this->assertSame('/var/www/html/app/Services/OrderService.php', $cluster->file);
        $this->assertSame(17, $cluster->line);
        $this->assertSame('#0 {main}', $cluster->stack_trace);
        $this->assertSame(2, $cluster->occurrence_count);
        $this->assertSame('2026-10-25 09:00:00', $cluster->first_seen_at->toDateTimeString());
        $this->assertSame('2026-10-25 11:00:00', $cluster->last_seen_at->toDateTimeString());

        $this->assertSame(2, $cluster->occurrences()->count());
        $this->assertTrue($cluster->is(Occurrence::query()->firstOrFail()->cluster));
    }

    #[Test]
    public function storing_the_same_log_twice_does_not_count_twice(): void
    {
        $entries = [
            $this->entry('2026-10-25 09:00:00', 'Order 1 not found'),
            $this->entry('2026-10-25 09:00:00', 'Order 1 not found'),
        ];

        $this->repository()->store($this->detect($entries));
        $again = $this->repository()->store($this->detect($entries));

        $this->assertFalse($again->isNew);
        $this->assertSame(0, $again->newOccurrences);
        $this->assertSame(2, Cluster::query()->sole()->occurrence_count);
        $this->assertSame(2, Occurrence::query()->count());
    }

    #[Test]
    public function it_merges_new_occurrences_into_an_existing_cluster(): void
    {
        $this->repository()->store($this->detect([$this->entry('2026-10-25 09:00:00', 'Order 1 not found')]));

        $stored = $this->repository()->store($this->detect([
            $this->entry('2026-10-24 22:00:00', 'Order 7 not found'),
            $this->entry('2026-10-26 08:00:00', 'Order 9 not found'),
        ]));

        $cluster = Cluster::query()->sole();
        $this->assertFalse($stored->isNew);
        $this->assertSame(2, $stored->newOccurrences);
        $this->assertSame(3, $cluster->occurrence_count);
        $this->assertSame('2026-10-24 22:00:00', $cluster->first_seen_at->toDateTimeString());
        $this->assertSame('2026-10-26 08:00:00', $cluster->last_seen_at->toDateTimeString());
        $this->assertSame('Order 9 not found', $cluster->sample_message);
    }

    #[Test]
    public function older_runs_do_not_replace_the_representative_entry(): void
    {
        $this->repository()->store($this->detect([$this->entry('2026-10-26 08:00:00', 'Order 9 not found')]));
        $this->repository()->store($this->detect([$this->entry('2026-10-20 08:00:00', 'Order 1 not found')]));

        $cluster = Cluster::query()->sole();
        $this->assertSame('Order 9 not found', $cluster->sample_message);
        $this->assertSame('2026-10-26 08:00:00', $cluster->last_seen_at->toDateTimeString());
        $this->assertSame('2026-10-20 08:00:00', $cluster->first_seen_at->toDateTimeString());
    }

    #[Test]
    public function it_stores_timestamps_in_app_time(): void
    {
        $this->repository()->store($this->detect([$this->entry('2026-10-24T22:15:03+02:00', 'Order 1 not found')]));

        $this->assertSame('2026-10-24 20:15:03', Cluster::query()->sole()->first_seen_at->toDateTimeString());
    }

    #[Test]
    public function clusters_relate_to_their_analyses(): void
    {
        $this->repository()->store($this->detect([$this->entry('2026-10-25 09:00:00', 'Order 1 not found')]));
        $cluster = Cluster::query()->sole();

        $first = $this->analysis($cluster, '2026-10-25 10:00:00');
        $latest = $this->analysis($cluster, '2026-10-25 11:00:00');

        $this->assertSame(2, $cluster->analyses()->count());
        $this->assertTrue($cluster->latestAnalysis?->is($latest));
        $this->assertTrue($cluster->is($first->cluster));
        $this->assertSame(['app/Services/OrderService.php'], $latest->affected_files);
        $this->assertSame(0.8, $latest->confidence);
    }

    #[Test]
    public function deleting_a_cluster_removes_its_occurrences_and_analyses(): void
    {
        $this->repository()->store($this->detect([$this->entry('2026-10-25 09:00:00', 'Order 1 not found')]));
        $cluster = Cluster::query()->sole();
        $this->analysis($cluster, '2026-10-25 10:00:00');

        $cluster->delete();

        $this->assertSame(0, Occurrence::query()->count());
        $this->assertSame(0, Analysis::query()->count());
    }

    private function repository(): ClusterRepository
    {
        return resolve(ClusterRepository::class);
    }

    /**
     * @param  list<LogEntry>  $entries
     */
    private function detect(array $entries): DetectedCluster
    {
        $aggregator = new ClusterAggregator(resolve(Fingerprinter::class));

        foreach ($entries as $entry) {
            $aggregator->add($entry);
        }

        $clusters = $aggregator->clusters();
        $this->assertCount(1, $clusters);

        return $clusters[0];
    }

    private function entry(string $time, string $message): LogEntry
    {
        return new LogEntry(
            timestamp: CarbonImmutable::parse($time),
            channel: 'production',
            level: LogLevel::Error,
            message: $message,
            exceptionClass: 'RuntimeException',
            file: '/var/www/html/app/Services/OrderService.php',
            line: 17,
            stackTrace: '#0 {main}',
            frames: [new StackFrame(0, '/var/www/html/app/Http/Controllers/OrderController.php', 42, 'show()')],
        );
    }

    private function analysis(Cluster $cluster, string $createdAt): Analysis
    {
        $this->travelTo(CarbonImmutable::parse($createdAt));

        return $cluster->analyses()->create([
            'provider' => 'anthropic',
            'model' => 'test-model',
            'root_cause' => 'The order was deleted.',
            'suggested_fix' => 'Use find() and handle null.',
            'affected_files' => ['app/Services/OrderService.php'],
            'severity' => 'medium',
            'confidence' => 0.8,
        ]);
    }
}
