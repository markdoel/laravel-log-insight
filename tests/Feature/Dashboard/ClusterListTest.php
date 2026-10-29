<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Dashboard\ClusterList;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Reporting\ReportRow;
use PHPUnit\Framework\Attributes\Test;

final class ClusterListTest extends DashboardTestCase
{
    private Cluster $frequent;

    private Cluster $critical;

    private Cluster $failed;

    private Cluster $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inLocalEnvironment();
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
        $this->makeAnalysis($this->critical, ['severity' => Severity::Critical, 'confidence' => 0.9]);

        $this->failed = $this->makeCluster([
            'occurrence_count' => 8,
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
    public function it_lists_all_clusters_most_frequent_first(): void
    {
        $component = $this->clusterList();

        $this->assertSame([$this->frequent->id, $this->critical->id, $this->failed->id, $this->pending->id], $this->listedIds($component));

        $component->assertSee('1–4 of 4 clusters');
        $component->assertSeeTextInOrder([
            '40', 'ModelNotFoundException', 'No query results for model [App\Models\Order] {n}', 'app/Http/Controllers/OrderController.php:24', '1 hour ago', 'analyzed', 'medium', '82%',
            'TypeError', 'app/Services/InvoiceRenderer.php:19', '3 hours ago', 'critical', '90%',
            '8', '6 days ago', 'failed',
            '3', 'CRITICAL', 'Payment gateway returned an unexpected response', '30 minutes ago', 'pending',
        ]);
    }

    #[Test]
    public function it_filters_by_time_range(): void
    {
        $component = $this->clusterList()->set('since', '24h');
        $this->assertSame([$this->frequent->id, $this->critical->id, $this->pending->id], $this->listedIds($component));

        $component->set('since', '1h');
        $this->assertSame([$this->frequent->id, $this->pending->id], $this->listedIds($component));

        $component->set('since', '');
        $this->assertCount(4, $this->listedIds($component));
    }

    #[Test]
    public function it_filters_by_minimum_severity(): void
    {
        $component = $this->clusterList()->set('severity', 'high');
        $this->assertSame([$this->critical->id], $this->listedIds($component));

        $component->set('severity', 'medium');
        $this->assertSame([$this->frequent->id, $this->critical->id], $this->listedIds($component));
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        $component = $this->clusterList()->set('status', 'analyzed');
        $this->assertSame([$this->frequent->id, $this->critical->id], $this->listedIds($component));

        $component->set('status', 'failed');
        $this->assertSame([$this->failed->id], $this->listedIds($component));

        $component->set('status', 'pending');
        $this->assertSame([$this->pending->id], $this->listedIds($component));
    }

    #[Test]
    public function an_analysis_wins_over_an_earlier_failure(): void
    {
        $this->failed->update(['analysis_failed_at' => null]);
        $this->critical->update(['analysis_failed_at' => CarbonImmutable::parse('2026-10-26 08:00:00')]);

        $component = $this->clusterList()->set('status', 'failed');
        $this->assertSame([], $this->listedIds($component));

        $component->set('status', 'analyzed');
        $this->assertSame([$this->frequent->id, $this->critical->id], $this->listedIds($component));
    }

    #[Test]
    public function filters_combine(): void
    {
        $component = $this->clusterList()
            ->set('since', '1h')
            ->set('status', 'analyzed');

        $this->assertSame([$this->frequent->id], $this->listedIds($component));
    }

    #[Test]
    public function filters_are_read_from_the_query_string(): void
    {
        $component = $this->clusterList(['severity' => 'critical']);

        $component->assertSet('severity', 'critical');
        $this->assertSame([$this->critical->id], $this->listedIds($component));
    }

    #[Test]
    public function unknown_filter_values_are_ignored(): void
    {
        $component = $this->clusterList(['since' => '1y', 'severity' => 'urgent', 'status' => 'done']);

        $component->assertSet('since', '')->assertSet('severity', '')->assertSet('status', '');
        $this->assertCount(4, $this->listedIds($component));
    }

    #[Test]
    public function it_explains_when_filters_match_nothing_and_resets_them(): void
    {
        $component = $this->clusterList()
            ->set('since', '1h')
            ->set('severity', 'critical')
            ->assertSee('No clusters match the filters.')
            ->call('resetFilters')
            ->assertSet('since', '')
            ->assertSet('severity', '');

        $this->assertCount(4, $this->listedIds($component));
    }

    #[Test]
    public function it_explains_an_empty_installation(): void
    {
        Cluster::query()->delete();

        $this->clusterList()
            ->assertSee('No clusters stored yet.')
            ->assertSee('log-insight:analyze');
    }

    #[Test]
    public function it_paginates(): void
    {
        $this->makeManyClusters(ClusterList::PER_PAGE + 3);

        $component = $this->clusterList();
        $firstPage = $this->listedIds($component);

        $this->assertCount(ClusterList::PER_PAGE, $firstPage);
        $component->assertSee('1–25 of 32 clusters')->assertSee('Page 1 of 2');

        $component->call('nextPage');
        $secondPage = $this->listedIds($component);

        $this->assertCount(7, $secondPage);
        $this->assertSame([], array_intersect($firstPage, $secondPage));
        $component->assertSee('26–32 of 32 clusters')->assertSee('Page 2 of 2');

        $component->call('previousPage');
        $this->assertSame($firstPage, $this->listedIds($component));
    }

    #[Test]
    public function the_page_is_read_from_the_query_string(): void
    {
        $this->makeManyClusters(ClusterList::PER_PAGE + 3);

        $component = $this->clusterList(['page' => '2']);

        $this->assertCount(7, $this->listedIds($component));
    }

    #[Test]
    public function changing_a_filter_goes_back_to_the_first_page(): void
    {
        $this->makeManyClusters(ClusterList::PER_PAGE + 3);

        $component = $this->clusterList()->call('nextPage');
        $this->assertSame(2, $this->paginator($component)->currentPage());

        $component->set('status', 'pending');
        $this->assertSame(1, $this->paginator($component)->currentPage());
        $this->assertCount(ClusterList::PER_PAGE, $this->listedIds($component));
    }

    #[Test]
    public function it_hides_the_pagination_on_a_single_page(): void
    {
        $this->clusterList()->assertDontSee('Page 1 of');
    }

    private function makeManyClusters(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeCluster(['occurrence_count' => 1, 'last_seen_at' => CarbonImmutable::parse('2026-10-26 10:00:00')->subMinutes($i)]);
        }
    }

    /**
     * @param  array<string, string>  $query
     * @return Testable<Component>
     */
    private function clusterList(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->test(ClusterList::class);
    }

    /**
     * @param  Testable<Component>  $component
     * @return LengthAwarePaginator<array-key, mixed>
     */
    private function paginator(Testable $component): LengthAwarePaginator
    {
        $clusters = $component->viewData('clusters');
        $this->assertInstanceOf(LengthAwarePaginator::class, $clusters);

        return $clusters;
    }

    /**
     * @param  Testable<Component>  $component
     * @return list<int>
     */
    private function listedIds(Testable $component): array
    {
        $ids = [];

        foreach ($this->paginator($component)->items() as $row) {
            $this->assertInstanceOf(ReportRow::class, $row);
            $ids[] = $row->cluster->id;
        }

        return $ids;
    }
}
