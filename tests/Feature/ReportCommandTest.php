<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Tests\Concerns\CreatesClusters;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReportCommandTest extends TestCase
{
    use CreatesClusters;
    use RefreshDatabase;

    private Cluster $frequent;

    private Cluster $critical;

    private Cluster $failed;

    private Cluster $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

        $this->frequent = $this->makeCluster(['occurrence_count' => 40, 'last_seen_at' => CarbonImmutable::parse('2026-10-26 11:00:00')]);
        $this->makeAnalysis($this->frequent);

        $this->critical = $this->makeCluster([
            'exception_class' => 'TypeError',
            'message' => 'App\Services\InvoiceRenderer::render(): Argument #{n} ($invoice) must be of type App\Models\Invoice, null given',
            'app_frame' => 'app/Services/InvoiceRenderer.php:19',
            'occurrence_count' => 12,
            'last_seen_at' => CarbonImmutable::parse('2026-10-26 09:00:00'),
        ]);
        $this->makeAnalysis($this->critical, ['severity' => Severity::Critical, 'confidence' => 0.9, 'root_cause' => 'Invoices without a customer reach the renderer.']);

        $this->failed = $this->makeCluster([
            'occurrence_count' => 12,
            'last_seen_at' => CarbonImmutable::parse('2026-10-20 08:00:00'),
            'analysis_failed_at' => CarbonImmutable::parse('2026-10-20 08:05:00'),
            'analysis_error' => 'The model declined to answer.',
        ]);

        $this->pending = $this->makeCluster([
            'type' => 'message',
            'level' => LogLevel::Critical,
            'exception_class' => null,
            'message' => 'Payment gateway returned an unexpected response',
            'app_frame' => null,
            'occurrence_count' => 3,
            'last_seen_at' => CarbonImmutable::parse('2026-10-26 11:30:00'),
        ]);
    }

    #[Test]
    public function it_lists_the_top_clusters_as_a_table(): void
    {
        $this->command('log-insight:report')
            ->expectsOutputToContain('Top 4 of 4 clusters, most frequent first.')
            ->expectsTable(
                ['ID', 'Count', 'Error', 'Message', 'Location', 'Last seen', 'Severity', 'Root cause'],
                [
                    [$this->frequent->id, 40, 'ModelNotFoundException', 'No query results for model [App\Models\Order] {n}', 'app/Http/Controllers/OrderController.php:24', '1 hour ago', 'medium (82%)', 'The order was deleted before the customer opened the link.'],
                    [$this->critical->id, 12, 'TypeError', 'App\Services\InvoiceRenderer::render(): Argument #...', 'app/Services/InvoiceRenderer.php:19', '3 hours ago', 'critical (90%)', 'Invoices without a customer reach the renderer.'],
                    [$this->failed->id, 12, 'ModelNotFoundException', 'No query results for model [App\Models\Order] {n}', 'app/Http/Controllers/OrderController.php:24', '6 days ago', 'failed', '-'],
                    [$this->pending->id, 3, 'CRITICAL', 'Payment gateway returned an unexpected response', '-', '30 minutes ago', 'pending', '-'],
                ],
            )
            ->assertSuccessful();
    }

    #[Test]
    public function it_limits_the_number_of_clusters(): void
    {
        $this->command('log-insight:report', ['--limit' => '2'])
            ->expectsOutputToContain('Top 2 of 4 clusters')
            ->assertSuccessful();
    }

    #[Test]
    public function it_filters_by_last_occurrence(): void
    {
        $this->assertSame([$this->frequent->id, $this->pending->id], array_column($this->reportClusters(['--since' => '2h']), 'id'));
    }

    #[Test]
    public function it_filters_by_minimum_severity(): void
    {
        $this->assertSame([$this->critical->id], array_column($this->reportClusters(['--severity' => 'high']), 'id'));
        $this->assertSame([$this->frequent->id, $this->critical->id], array_column($this->reportClusters(['--severity' => 'MEDIUM']), 'id'));
    }

    #[Test]
    public function it_prints_machine_readable_json(): void
    {
        $report = $this->jsonReport(['--limit' => '2', '--since' => '7d']);

        $this->assertSame('2026-10-26T12:00:00+00:00', $report['generated_at']);
        $this->assertSame(['limit' => 2, 'since' => '2026-10-19T12:00:00+00:00', 'min_severity' => null], $report['filters']);
        $this->assertSame(4, $report['total']);
        $clusters = $this->reportClusters(['--limit' => '2', '--since' => '7d']);
        $this->assertCount(2, $clusters);

        $first = $clusters[0];
        $this->assertSame($this->frequent->id, $first['id']);
        $this->assertSame('exception', $first['type']);
        $this->assertSame('Illuminate\Database\Eloquent\ModelNotFoundException', $first['exception_class']);
        $this->assertSame('app/Http/Controllers/OrderController.php:24', $first['location']);
        $this->assertSame(40, $first['occurrences']);
        $this->assertSame('2026-10-26T11:00:00+00:00', $first['last_seen_at']);
        $this->assertSame('analyzed', $first['status']);
        $this->assertSame([
            'severity' => 'medium',
            'confidence' => 0.82,
            'root_cause' => 'The order was deleted before the customer opened the link.',
            'suggested_fix' => 'Use find() and return a 404 view when the order is missing.',
            'affected_files' => ['app/Http/Controllers/OrderController.php'],
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'analyzed_at' => '2026-10-26T12:00:00+00:00',
        ], $first['analysis']);
    }

    #[Test]
    public function json_includes_failures_and_pending_clusters(): void
    {
        $clusters = array_column($this->reportClusters(), null, 'id');
        $failed = $clusters[$this->failed->id] ?? [];
        $pending = $clusters[$this->pending->id] ?? [];

        $this->assertSame('failed', $failed['status'] ?? null);
        $this->assertSame('The model declined to answer.', $failed['analysis_error'] ?? null);
        $this->assertArrayHasKey('analysis', $failed);
        $this->assertNull($failed['analysis']);
        $this->assertSame('pending', $pending['status'] ?? null);
        $this->assertSame('critical', $pending['level'] ?? null);
    }

    #[Test]
    public function it_explains_an_empty_report(): void
    {
        Cluster::query()->delete();

        $this->command('log-insight:report')
            ->expectsOutputToContain('No clusters stored yet. Run log-insight:analyze first.')
            ->assertSuccessful();

        $this->assertSame([], $this->reportClusters());
    }

    #[Test]
    public function it_explains_when_filters_match_nothing(): void
    {
        $this->command('log-insight:report', ['--since' => '1m'])
            ->expectsOutputToContain('No clusters match the filters.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_rejects_invalid_options(): void
    {
        $this->command('log-insight:report', ['--limit' => '0'])->expectsOutputToContain('positive whole number')->assertFailed();
        $this->command('log-insight:report', ['--limit' => 'ten'])->expectsOutputToContain('positive whole number')->assertFailed();
        $this->command('log-insight:report', ['--severity' => 'urgent'])->expectsOutputToContain('low, medium, high, critical')->assertFailed();
        $this->command('log-insight:report', ['--since' => 'yesterday'])->expectsOutputToContain('Invalid --since value')->assertFailed();
    }

    /**
     * Runs the command for real (no output mocking) and decodes its JSON.
     *
     * @param  array<string, string>  $options
     * @return array<array-key, mixed>
     */
    private function jsonReport(array $options = []): array
    {
        $this->withoutMockingConsoleOutput();

        $this->assertSame(0, Artisan::call('log-insight:report', [...$options, '--json' => true]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return $report;
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array<array-key, mixed>>
     */
    private function reportClusters(array $options = []): array
    {
        $clusters = $this->jsonReport($options)['clusters'] ?? null;
        $this->assertIsArray($clusters);

        $result = [];

        foreach ($clusters as $cluster) {
            $this->assertIsArray($cluster);
            $result[] = $cluster;
        }

        return $result;
    }
}
