<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Jobs\AnalyzeClusterJob;
use MarkusDoell\LogInsight\Models\Analysis;
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
    public function new_clusters_are_queued_for_ai_analysis(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        config()->set('log-insight.queue.connection', 'redis');
        config()->set('log-insight.queue.name', 'insights');
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Queued 5 clusters for AI analysis.')
            ->assertSuccessful();

        Queue::assertPushed(AnalyzeClusterJob::class, 5);
        Queue::assertPushedOn('insights', AnalyzeClusterJob::class);
        Queue::assertPushed(AnalyzeClusterJob::class, fn (AnalyzeClusterJob $job): bool => $job->connection === 'redis');
    }

    #[Test]
    public function analyzed_and_failed_clusters_are_not_queued_again(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        Queue::fake();
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])->assertSuccessful();

        $analyzed = Cluster::query()->orderBy('id')->firstOrFail();
        $failed = Cluster::query()->orderBy('id')->skip(1)->firstOrFail();
        $analyzed->analyses()->create([
            'provider' => 'anthropic', 'model' => 'm', 'root_cause' => 'x', 'suggested_fix' => 'y',
            'affected_files' => [], 'severity' => 'low', 'confidence' => 0.5,
        ]);
        $failed->forceFill(['analysis_failed_at' => now(), 'analysis_error' => 'refused'])->save();

        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Queued 3 clusters for AI analysis.')
            ->assertSuccessful();

        Queue::assertPushed(AnalyzeClusterJob::class, 3);
        Queue::assertNotPushed(AnalyzeClusterJob::class, fn (AnalyzeClusterJob $job): bool => in_array($job->clusterId, [$analyzed->id, $failed->id], true));
    }

    #[Test]
    public function nothing_is_queued_without_an_api_key(): void
    {
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('No API key configured')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function nothing_is_queued_when_ai_analysis_is_disabled(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        config()->set('log-insight.analysis.enabled', false);
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('AI analysis is disabled')
            ->assertSuccessful();

        $this->assertSame(5, Cluster::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_dry_run_queues_nothing(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--dry-run' => true])
            ->doesntExpectOutputToContain('Queued')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_full_pipeline_stores_analyses_with_a_sync_queue(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        Event::fake([AnalysisCompleted::class]);
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => (string) json_encode([
                'root_cause' => 'Cause', 'suggested_fix' => 'Fix', 'affected_files' => [], 'severity' => 'high', 'confidence' => 0.7,
            ])]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])->assertSuccessful();

        Http::assertSentCount(5);
        $this->assertSame(5, Analysis::query()->count());
        Event::assertDispatchedTimes(AnalysisCompleted::class, 5);

        // A second run sends nothing: every cluster has its analysis.
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('No clusters waiting for AI analysis.')
            ->assertSuccessful();

        Http::assertSentCount(5);
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
