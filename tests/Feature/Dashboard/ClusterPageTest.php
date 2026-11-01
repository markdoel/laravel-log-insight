<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Dashboard\ClusterPage;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\Occurrence;
use MarkusDoell\LogInsight\Reporting\ClusterDetail;
use MarkusDoell\LogInsight\Reporting\ClusterDetailReport;
use MarkusDoell\LogInsight\Reporting\DailyCount;
use PHPUnit\Framework\Attributes\Test;

final class ClusterPageTest extends DashboardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->inLocalEnvironment();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        Queue::fake();
    }

    #[Test]
    public function it_shows_the_cluster_facts(): void
    {
        $cluster = $this->makeCluster(['occurrence_count' => 1234]);

        $this->clusterPage($cluster)
            ->assertSee('Illuminate\Database\Eloquent\ModelNotFoundException')
            ->assertSee('No query results for model [App\Models\Order] {n}')
            ->assertSee('No query results for model [App\Models\Order] 48213')
            ->assertSeeTextInOrder(['Occurrences', '1,234', 'Level', 'ERROR', 'First seen', 'Sun, Oct 25, 2026 9:30 AM', 'Last seen', 'Sun, Oct 25, 2026 12:30 PM'])
            ->assertSee('app/Http/Controllers/OrderController.php:24')
            ->assertSee('vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:780')
            ->assertSee($cluster->fingerprint)
            ->assertSee('pending');
    }

    #[Test]
    public function it_shows_the_latest_analysis(): void
    {
        $cluster = $this->makeCluster();
        $this->makeAnalysis($cluster, ['root_cause' => 'An older guess.', 'created_at' => CarbonImmutable::parse('2026-10-25 13:00:00')]);
        $this->makeAnalysis($cluster, [
            'model' => 'claude-sonnet-5-5',
            'severity' => Severity::High,
            'confidence' => 0.67,
            'affected_files' => ['app/Http/Controllers/OrderController.php', 'routes/web.php'],
            'created_at' => CarbonImmutable::parse('2026-10-26 10:00:00'),
        ]);

        $this->clusterPage($cluster)
            ->assertSeeTextInOrder([
                'Severity', 'high', 'Confidence', '67%', 'Model', 'anthropic / claude-sonnet-5-5', 'Analyzed', '2 hours ago',
                'Root cause', 'The order was deleted before the customer opened the link.',
                'Suggested fix', 'Use find() and return a 404 view when the order is missing.',
                'Affected files', 'app/Http/Controllers/OrderController.php', 'routes/web.php',
            ])
            ->assertSee('analyzed')
            ->assertDontSee('An older guess.')
            ->assertDontSee('Retry analysis');
    }

    #[Test]
    public function it_shows_the_stack_trace(): void
    {
        $cluster = $this->makeCluster();

        $this->clusterPage($cluster)
            ->assertSee('Stack trace')
            ->assertSee("#0 /var/www/html/app/Http/Controllers/OrderController.php(24): Illuminate\\Database\\Eloquent\\Model::__callStatic('findOrFail', Array)");
    }

    #[Test]
    public function it_hides_the_stack_trace_of_plain_messages(): void
    {
        $cluster = $this->makeCluster(['type' => 'message', 'exception_class' => null, 'stack_trace' => null, 'level' => LogLevel::Critical]);

        $this->clusterPage($cluster)
            ->assertSee('CRITICAL')
            ->assertDontSee('Stack trace');
    }

    #[Test]
    public function it_counts_occurrences_per_day_for_the_last_14_days(): void
    {
        $cluster = $this->makeCluster();
        $this->makeOccurrences($cluster, [
            '2026-10-12 23:59:59', // 15 days ago, outside the chart
            '2026-10-13 00:00:00', // first day of the chart
            '2026-10-20 08:00:00',
            '2026-10-20 18:00:00',
            '2026-10-20 23:59:59',
            '2026-10-26 00:00:00', // today
            '2026-10-26 11:59:00',
        ]);

        $counts = $this->detail($this->clusterPage($cluster))->dailyCounts;

        $this->assertCount(14, $counts);
        $this->assertSame('2026-10-13', $counts[0]->date->toDateString());
        $this->assertSame('2026-10-26', $counts[13]->date->toDateString());
        $this->assertSame(
            [1, 0, 0, 0, 0, 0, 0, 3, 0, 0, 0, 0, 0, 2],
            array_map(static fn (DailyCount $day): int => $day->count, $counts),
        );
    }

    #[Test]
    public function the_chart_has_one_bar_per_day_with_occurrences(): void
    {
        $cluster = $this->makeCluster();
        $this->makeOccurrences($cluster, ['2026-10-20 08:00:00', '2026-10-20 09:00:00', '2026-10-26 10:00:00']);

        $component = $this->clusterPage($cluster)
            ->assertSee('3 in the last 14 days, at most 2 a day.')
            ->assertSee('Tue, Oct 20: 2 occurrences')
            ->assertSee('Mon, Oct 26: 1 occurrence')
            ->assertSee('Sat, Oct 17: 0 occurrences');

        $this->assertSame(2, substr_count($component->html(), 'class="li-chart-bar"'));
        $this->assertSame(14, substr_count($component->html(), 'class="li-chart-hit"'));
    }

    #[Test]
    public function the_chart_is_empty_without_recent_occurrences(): void
    {
        $cluster = $this->makeCluster();
        $this->makeOccurrences($cluster, ['2026-09-01 08:00:00']);

        $component = $this->clusterPage($cluster)->assertSee('0 in the last 14 days.');

        $this->assertSame(0, substr_count($component->html(), 'class="li-chart-bar"'));
    }

    #[Test]
    public function the_chart_ignores_other_clusters(): void
    {
        $cluster = $this->makeCluster();
        $this->makeOccurrences($this->makeCluster(), ['2026-10-26 08:00:00']);

        $counts = $this->detail($this->clusterPage($cluster))->dailyCounts;

        $this->assertSame(0, array_sum(array_map(static fn (DailyCount $day): int => $day->count, $counts)));
    }

    #[Test]
    public function it_lists_the_latest_occurrences_most_recent_first(): void
    {
        $cluster = $this->makeCluster(['occurrence_count' => 25]);
        $timestamps = [];

        for ($i = 0; $i < 25; $i++) {
            $timestamps[] = CarbonImmutable::parse('2026-10-26 11:00:00')->subMinutes($i)->toDateTimeString();
        }

        $this->makeOccurrences($cluster, $timestamps);

        $component = $this->clusterPage($cluster);
        $occurrences = $this->detail($component)->latestOccurrences;

        $this->assertCount(ClusterDetailReport::LATEST_OCCURRENCES, $occurrences);
        $this->assertSame('2026-10-26 11:00:00', $occurrences[0]->occurred_at->toDateTimeString());
        $this->assertSame('2026-10-26 10:41:00', $occurrences[19]->occurred_at->toDateTimeString());

        $component
            ->assertSeeTextInOrder(['Latest occurrences', '2026-10-26 11:00:00', 'ERROR', 'production', 'Order 0 not found', '2026-10-26 10:59:00'])
            ->assertDontSee('2026-10-26 10:40:00')
            ->assertSee('The 20 most recent of 25.');
    }

    #[Test]
    public function it_shows_a_pending_cluster(): void
    {
        $this->clusterPage($this->makeCluster())
            ->assertSee('Not analyzed yet.')
            ->assertSee('No occurrences stored.')
            ->assertDontSee('Retry analysis');
    }

    #[Test]
    public function it_shows_why_the_analysis_failed(): void
    {
        $cluster = $this->failedCluster();

        $this->clusterPage($cluster)
            ->assertSee('failed')
            ->assertSee('The analysis failed')
            ->assertSee('The model declined to answer.')
            ->assertSee('2 hours ago')
            ->assertSee('Retry analysis');
    }

    #[Test]
    public function retrying_clears_the_failure_and_queues_the_analysis(): void
    {
        config()->set('log-insight.queue.connection', 'redis');
        config()->set('log-insight.queue.name', 'insights');
        $cluster = $this->failedCluster();

        $this->clusterPage($cluster)
            ->call('retryAnalysis')
            ->assertSee('The analysis was queued again.')
            ->assertSee('pending')
            ->assertDontSee('The model declined to answer.')
            ->assertDontSee('Retry analysis');

        $cluster->refresh();
        $this->assertNull($cluster->analysis_failed_at);
        $this->assertNull($cluster->analysis_error);

        Queue::assertPushed(AnalyzeClusterJob::class, 1);
        Queue::assertPushedOn('insights', AnalyzeClusterJob::class, fn (AnalyzeClusterJob $job): bool => $job->clusterId === $cluster->id && $job->connection === 'redis');
    }

    #[Test]
    public function retrying_twice_queues_the_analysis_once(): void
    {
        $cluster = $this->failedCluster();

        $first = $this->clusterPage($cluster);
        $second = $this->clusterPage($cluster);

        $first->call('retryAnalysis');
        $second->call('retryAnalysis')->assertSee('The analysis cannot be retried right now.');

        Queue::assertPushed(AnalyzeClusterJob::class, 1);
    }

    #[Test]
    public function analyzed_clusters_are_never_sent_again(): void
    {
        $cluster = $this->failedCluster();
        $this->makeAnalysis($cluster);

        $this->clusterPage($cluster)
            ->assertDontSee('Retry analysis')
            ->assertDontSee('The model declined to answer.')
            ->call('retryAnalysis')
            ->assertSee('The analysis cannot be retried right now.');

        Queue::assertNothingPushed();
        $this->assertNotNull($cluster->refresh()->analysis_failed_at);
    }

    #[Test]
    public function pending_clusters_are_not_queued_by_the_retry_action(): void
    {
        $cluster = $this->makeCluster();

        $this->clusterPage($cluster)->call('retryAnalysis');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_explains_why_a_failed_analysis_cannot_be_retried_without_an_api_key(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', null);
        $cluster = $this->failedCluster();

        $this->clusterPage($cluster)
            ->assertDontSee('Retry analysis')
            ->assertSee('No API key is configured for the AI provider.')
            ->call('retryAnalysis');

        Queue::assertNothingPushed();
        $this->assertNotNull($cluster->refresh()->analysis_failed_at);
    }

    #[Test]
    public function it_explains_why_a_failed_analysis_cannot_be_retried_while_analysis_is_disabled(): void
    {
        config()->set('log-insight.analysis.enabled', false);
        $cluster = $this->failedCluster();

        $this->clusterPage($cluster)
            ->assertDontSee('Retry analysis')
            ->assertSee('AI analysis is disabled')
            ->call('retryAnalysis');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_unknown_cluster_is_not_found(): void
    {
        Livewire::test(ClusterPage::class, ['clusterId' => 999])->assertNotFound();
    }

    #[Test]
    public function a_deleted_cluster_cannot_be_retried(): void
    {
        $cluster = $this->failedCluster();
        $component = $this->clusterPage($cluster);
        $cluster->delete();

        $component->call('retryAnalysis')->assertNotFound();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_cluster_id_cannot_be_changed_by_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->clusterPage($this->makeCluster())->set('clusterId', 2);
    }

    /**
     * @return Testable<Component>
     */
    private function clusterPage(Cluster $cluster): Testable
    {
        return Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id]);
    }

    /**
     * @param  Testable<Component>  $component
     */
    private function detail(Testable $component): ClusterDetail
    {
        $detail = $component->viewData('detail');
        $this->assertInstanceOf(ClusterDetail::class, $detail);

        return $detail;
    }

    private function failedCluster(): Cluster
    {
        return $this->makeCluster([
            'analysis_failed_at' => CarbonImmutable::parse('2026-10-26 10:00:00'),
            'analysis_error' => 'The model declined to answer.',
        ]);
    }

    /**
     * @param  list<string>  $timestamps
     */
    private function makeOccurrences(Cluster $cluster, array $timestamps): void
    {
        foreach ($timestamps as $i => $timestamp) {
            Occurrence::query()->create([
                'cluster_id' => $cluster->id,
                'hash' => sha1($cluster->id.'-'.$timestamp.'-'.$i),
                'occurred_at' => CarbonImmutable::parse($timestamp),
                'level' => LogLevel::Error,
                'channel' => 'production',
                'message' => "Order {$i} not found",
            ]);
        }
    }
}
