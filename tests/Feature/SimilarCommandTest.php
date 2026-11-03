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

final class SimilarCommandTest extends TestCase
{
    use FakesVoyage;
    use RefreshDatabase;

    private Cluster $target;

    private Cluster $closest;

    private Cluster $close;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSimilarity();
        Http::fake();

        $this->target = $this->makeCluster();
        $this->embed($this->target, [1.0, 0.0, 0.0]);

        $this->closest = $this->makeCluster(['app_frame' => 'app/Http/Controllers/OrderController.php:31', 'occurrence_count' => 7]);
        $this->embed($this->closest, [0.9, 0.1, 0.0]);
        $this->makeAnalysis($this->closest);

        $this->close = $this->makeCluster([
            'exception_class' => 'TypeError',
            'message' => 'App\Services\InvoiceRenderer::render(): Argument #{n} ($invoice) must be of type App\Models\Invoice, null given',
            'app_frame' => 'app/Services/InvoiceRenderer.php:19',
        ]);
        $this->embed($this->close, [0.8, 0.6, 0.0]);
    }

    #[Test]
    public function it_shows_similar_clusters_as_a_table(): void
    {
        $this->command('log-insight:similar', ['cluster' => (string) $this->target->id, '--threshold' => '0.75'])
            ->expectsOutputToContain("Cluster {$this->target->id}: ModelNotFoundException")
            ->expectsTable(
                ['ID', 'Similarity', 'Count', 'Error', 'Message', 'Location', 'Status'],
                [
                    [$this->closest->id, '99.4%', 7, 'ModelNotFoundException', 'No query results for model [App\Models\Order] {n}', 'app/Http/Controllers/OrderController.php:31', 'analyzed'],
                    [$this->close->id, '80%', 2, 'TypeError', 'App\Services\InvoiceRenderer::render(): Argument #...', 'app/Services/InvoiceRenderer.php:19', 'pending'],
                ],
            )
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_uses_the_configured_threshold_and_limit(): void
    {
        config()->set('log-insight.similarity.limit', 1);
        config()->set('log-insight.similarity.threshold', 0.5);

        Artisan::call('log-insight:similar', ['cluster' => $this->target->id, '--json' => true]);
        $data = json_decode(Artisan::output(), true);

        $this->assertIsArray($data);
        $this->assertSame(0.5, $data['threshold']);
        $this->assertSame(1, $data['limit']);
        $this->assertIsArray($data['similar']);
        $this->assertCount(1, $data['similar']);
    }

    #[Test]
    public function it_prints_json(): void
    {
        Artisan::call('log-insight:similar', ['cluster' => $this->target->id, '--json' => true, '--limit' => '5', '--threshold' => '0.9']);
        $data = json_decode(Artisan::output(), true);

        $this->assertIsArray($data);
        $this->assertSame([
            'id' => $this->target->id,
            'exception_class' => 'Illuminate\Database\Eloquent\ModelNotFoundException',
            'level' => 'error',
            'message' => 'No query results for model [App\Models\Order] {n}',
            'location' => 'app/Http/Controllers/OrderController.php:24',
        ], $data['cluster']);
        $this->assertSame('voyage-4', $data['model']);
        $this->assertSame(0.9, $data['threshold']);
        $this->assertSame(5, $data['limit']);
        $this->assertIsArray($data['similar']);
        $this->assertCount(1, $data['similar']);
        $this->assertIsArray($data['similar'][0]);
        $this->assertSame($this->closest->id, $data['similar'][0]['id']);
        $this->assertSame(0.9939, $data['similar'][0]['similarity']);
        $this->assertSame('analyzed', $data['similar'][0]['status']);
    }

    #[Test]
    public function it_says_when_nothing_is_similar(): void
    {
        $this->command('log-insight:similar', ['cluster' => (string) $this->target->id, '--threshold' => '0.999'])
            ->expectsOutputToContain('No other cluster has a similarity of at least 99.9%.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_explains_a_missing_embedding(): void
    {
        $fresh = $this->makeCluster();

        $this->command('log-insight:similar', ['cluster' => (string) $fresh->id])
            ->expectsOutputToContain('no embedding yet')
            ->assertSuccessful();

        Artisan::call('log-insight:similar', ['cluster' => $fresh->id, '--json' => true]);
        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data);
        $this->assertNull($data['similar']);
    }

    #[Test]
    public function it_fails_for_an_unknown_cluster(): void
    {
        $this->command('log-insight:similar', ['cluster' => '999999'])
            ->expectsOutputToContain('Cluster [999999] does not exist.')
            ->assertFailed();
    }

    #[Test]
    public function it_validates_its_input(): void
    {
        $this->command('log-insight:similar', ['cluster' => 'abc'])
            ->expectsOutputToContain('The cluster argument must be a positive whole number.')
            ->assertFailed();

        $this->command('log-insight:similar', ['cluster' => (string) $this->target->id, '--limit' => '0'])
            ->expectsOutputToContain('The --limit option must be a positive whole number.')
            ->assertFailed();

        $this->command('log-insight:similar', ['cluster' => (string) $this->target->id, '--threshold' => '1.2'])
            ->expectsOutputToContain('The --threshold option must be a number greater than 0 and at most 1.')
            ->assertFailed();
    }

    #[Test]
    public function it_needs_an_api_key(): void
    {
        config()->set('log-insight.similarity.api_key', null);

        $this->command('log-insight:similar', ['cluster' => (string) $this->target->id])
            ->expectsOutputToContain('Similar clusters are disabled.')
            ->assertFailed();
    }
}
