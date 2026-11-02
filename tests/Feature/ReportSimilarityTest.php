<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReportSimilarityTest extends TestCase
{
    use FakesVoyage;
    use RefreshDatabase;

    private Cluster $frequent;

    private Cluster $similar;

    private Cluster $fresh;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->frequent = $this->makeCluster(['occurrence_count' => 40]);
        $this->embed($this->frequent, [1.0, 0.0]);

        $this->similar = $this->makeCluster(['occurrence_count' => 20, 'app_frame' => 'app/Http/Controllers/OrderController.php:31']);
        $this->embed($this->similar, [0.95, 0.05]);

        $this->fresh = $this->makeCluster(['occurrence_count' => 1]);
    }

    #[Test]
    public function the_json_report_lists_similar_clusters_when_enabled(): void
    {
        $this->enableSimilarity();

        $clusters = $this->jsonClusters();

        $this->assertSame([$this->frequent->id, $this->similar->id, $this->fresh->id], array_column($clusters, 'id'));

        $this->assertIsArray($clusters[0]['similar']);
        $this->assertCount(1, $clusters[0]['similar']);
        $this->assertIsArray($clusters[0]['similar'][0]);
        $this->assertSame($this->similar->id, $clusters[0]['similar'][0]['id']);
        $this->assertSame('app/Http/Controllers/OrderController.php:31', $clusters[0]['similar'][0]['location']);

        $this->assertIsArray($clusters[1]['similar']);
        $this->assertIsArray($clusters[1]['similar'][0]);
        $this->assertSame($this->frequent->id, $clusters[1]['similar'][0]['id']);

        // Not embedded yet.
        $this->assertArrayHasKey('similar', $clusters[2]);
        $this->assertNull($clusters[2]['similar']);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_json_report_is_unchanged_when_disabled(): void
    {
        foreach ($this->jsonClusters() as $cluster) {
            $this->assertArrayNotHasKey('similar', $cluster);
        }
    }

    #[Test]
    public function the_json_report_is_unchanged_without_an_api_key(): void
    {
        config()->set('log-insight.similarity.enabled', true);

        foreach ($this->jsonClusters() as $cluster) {
            $this->assertArrayNotHasKey('similar', $cluster);
        }
    }

    #[Test]
    public function the_table_report_is_unchanged(): void
    {
        $this->enableSimilarity();

        $this->command('log-insight:report')
            ->doesntExpectOutputToContain('similar')
            ->assertSuccessful();
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function jsonClusters(): array
    {
        Artisan::call('log-insight:report', ['--json' => true]);
        $data = json_decode(Artisan::output(), true);

        $this->assertIsArray($data);
        $this->assertIsArray($data['clusters']);

        $clusters = [];

        foreach ($data['clusters'] as $cluster) {
            $this->assertIsArray($cluster);
            $clusters[] = $cluster;
        }

        return $clusters;
    }
}
