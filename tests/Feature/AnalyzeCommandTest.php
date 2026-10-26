<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\Occurrence;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AnalyzeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../Fixtures/logs';

    #[Test]
    public function it_analyzes_a_single_file(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Parsed 8 entries (4 with exception) from 1 file.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_stores_clusters_and_lists_them(): void
    {
        Event::fake([ClusterDetected::class]);

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Found 5 clusters (5 new).')
            ->expectsOutputToContain('app/Http/Controllers/OrderController.php:42')
            ->assertSuccessful();

        $this->assertSame(5, Cluster::query()->count());
        $this->assertSame(6, Occurrence::query()->count());

        $notFound = Cluster::query()->where('exception_class', 'Illuminate\Database\Eloquent\ModelNotFoundException')->sole();
        $this->assertSame(2, $notFound->occurrence_count);
        $this->assertSame('No query results for model [App\Models\Order] 50117', $notFound->sample_message);

        Event::assertDispatchedTimes(ClusterDetected::class, 5);
    }

    #[Test]
    public function a_second_run_over_the_same_log_finds_no_new_clusters(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])->assertSuccessful();

        Event::fake([ClusterDetected::class]);

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Found 5 clusters (0 new).')
            ->assertSuccessful();

        $this->assertSame(6, Occurrence::query()->count());
        Event::assertNotDispatched(ClusterDetected::class);
    }

    #[Test]
    public function a_dry_run_stores_nothing(): void
    {
        Event::fake([ClusterDetected::class]);

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--dry-run' => true])
            ->expectsOutputToContain('Found 5 clusters.')
            ->assertSuccessful();

        $this->assertSame(0, Cluster::query()->count());
        Event::assertNotDispatched(ClusterDetected::class);
    }

    #[Test]
    public function the_minimum_level_is_configurable(): void
    {
        config()->set('log-insight.clustering.min_level', 'critical');

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Found 1 cluster (1 new).')
            ->assertSuccessful();

        $this->assertSame('TypeError', Cluster::query()->sole()->exception_class);
    }

    #[Test]
    public function it_analyzes_the_configured_log_directory(): void
    {
        config()->set('log-insight.logs.path', self::FIXTURES.'/daily');

        $this->command('log-insight:analyze')
            ->expectsOutputToContain('Parsed 5 entries (2 with exception) from 2 files.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_limits_the_analysis_with_since(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00'));
        config()->set('log-insight.logs.path', self::FIXTURES.'/daily');

        $this->command('log-insight:analyze', ['--since' => '6h'])
            ->expectsOutputToContain('Parsed 3 entries (1 with exception) from 1 file.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_announces_a_dry_run(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_when_no_files_are_found(): void
    {
        config()->set('log-insight.logs.path', self::FIXTURES.'/does-not-exist');

        $this->command('log-insight:analyze')
            ->expectsOutputToContain('No log files found.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_for_a_missing_file(): void
    {
        $this->command('log-insight:analyze', ['--file' => '/nowhere/laravel.log'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_for_an_invalid_since_value(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--since' => 'yesterday'])
            ->expectsOutputToContain('Invalid --since value')
            ->assertFailed();
    }
}
