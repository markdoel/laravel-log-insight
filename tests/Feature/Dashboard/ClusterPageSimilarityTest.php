<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use MarkusDoell\LogInsight\Dashboard\ClusterPage;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use PHPUnit\Framework\Attributes\Test;

final class ClusterPageSimilarityTest extends DashboardTestCase
{
    use FakesVoyage;

    private Cluster $target;

    private Cluster $similar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inLocalEnvironment();
        Http::fake();

        $this->target = $this->makeCluster();
        $this->embed($this->target, [1.0, 0.0]);

        $this->similar = $this->makeCluster([
            'exception_class' => 'Illuminate\Database\Eloquent\RecordsNotFoundException',
            'message' => 'No query results for model [App\Models\Invoice] {n}',
            'app_frame' => 'app/Http/Controllers/InvoiceController.php:18',
            'occurrence_count' => 9,
        ]);
        $this->embed($this->similar, [0.9, 0.1]);
    }

    #[Test]
    public function it_lists_similar_clusters_with_links(): void
    {
        $this->enableSimilarity();

        $this->clusterPage($this->target)
            ->assertSee('Similar clusters')
            ->assertSee('at 85% similarity or more')
            ->assertSeeTextInOrder(['99.4%', '9', 'RecordsNotFoundException', 'No query results for model [App\Models\Invoice] {n}', 'app/Http/Controllers/InvoiceController.php:18', 'pending'])
            ->assertSee(route('log-insight.cluster', $this->similar->id), false);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_says_when_nothing_is_similar(): void
    {
        $this->enableSimilarity();
        config()->set('log-insight.similarity.threshold', 0.999);

        $this->clusterPage($this->target)
            ->assertSee('No similar clusters.')
            ->assertDontSee('App\Models\Invoice');
    }

    #[Test]
    public function it_explains_a_missing_embedding(): void
    {
        $this->enableSimilarity();
        $fresh = $this->makeCluster();

        $this->clusterPage($fresh)
            ->assertSee('Similar clusters')
            ->assertSee('Not embedded yet.');
    }

    #[Test]
    public function the_section_is_hidden_when_disabled(): void
    {
        $this->clusterPage($this->target)
            ->assertDontSee('Similar clusters')
            ->assertDontSee('App\Models\Invoice');
    }

    #[Test]
    public function the_section_is_hidden_without_an_api_key(): void
    {
        config()->set('log-insight.similarity.enabled', true);

        $this->clusterPage($this->target)->assertDontSee('Similar clusters');
    }

    /**
     * @return Testable<Component>
     */
    private function clusterPage(Cluster $cluster): Testable
    {
        return Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id]);
    }
}
