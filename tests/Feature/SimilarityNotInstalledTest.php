<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Jobs\EmbedClusterJob;
use MarkusDoell\LogInsight\Similarity\ClusterEmbedder;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Similar clusters are enabled, but only the original migration has been
 * run, like an existing installation that has not published the new one.
 */
final class SimilarityNotInstalledTest extends TestCase
{
    use FakesVoyage;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The listener is registered while the provider boots, so this cannot wait for setUp().
        $app['config']->set('log-insight.similarity.enabled', true);
        $app['config']->set('log-insight.similarity.api_key', 'voyage-test-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    #[Test]
    public function the_embedder_explains_the_missing_migration(): void
    {
        $cluster = $this->makeCluster();

        try {
            resolve(ClusterEmbedder::class)->embed($cluster);
            $this->fail('Expected an exception.');
        } catch (NotInstalled $exception) {
            $this->assertStringContainsString('php artisan vendor:publish --tag=log-insight-similarity-migrations', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_job_fails_without_calling_the_provider(): void
    {
        $cluster = $this->makeCluster();

        (new EmbedClusterJob($cluster->id))->handle(resolve(ClusterEmbedder::class));

        Http::assertNothingSent();
    }

    #[Test]
    public function the_commands_explain_the_missing_migration(): void
    {
        Queue::fake();
        $cluster = $this->makeCluster();

        $this->command('log-insight:similar', ['cluster' => $cluster->id])
            ->expectsOutputToContain('log-insight-similarity-migrations')
            ->assertFailed();

        $this->command('log-insight:embed')
            ->expectsOutputToContain('log-insight-similarity-migrations')
            ->assertFailed();

        $this->command('log-insight:report', ['--json' => true])
            ->expectsOutputToContain('log-insight-similarity-migrations')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_table_report_and_analyze_keep_working(): void
    {
        Queue::fake();

        $this->command('log-insight:report')->assertSuccessful();
        $this->command('log-insight:analyze', ['--file' => __DIR__.'/../Fixtures/logs/single/laravel.log'])->assertSuccessful();

        // Queued, and the job explains the missing table once it runs.
        Queue::assertPushed(EmbedClusterJob::class, 5);
    }
}
