<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use MarkusDoell\LogInsight\Events\ClusterDetected;
use MarkusDoell\LogInsight\Jobs\EmbedClusterJob;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Similar clusters are off by default, even with an API key.
 */
final class EmbeddingDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('log-insight.similarity.api_key', 'voyage-test-key');
    }

    #[Test]
    public function it_is_disabled_by_default(): void
    {
        $this->assertFalse(config('log-insight.similarity.enabled'));
    }

    #[Test]
    public function no_listener_is_registered(): void
    {
        $this->assertFalse(Event::hasListeners(ClusterDetected::class));
    }

    #[Test]
    public function analyze_queues_no_embeddings(): void
    {
        Queue::fake();

        $this->command('log-insight:analyze', ['--file' => __DIR__.'/../Fixtures/logs/single/laravel.log'])->assertSuccessful();

        Queue::assertNotPushed(EmbedClusterJob::class);
    }

    #[Test]
    public function the_commands_explain_how_to_enable_it(): void
    {
        $this->command('log-insight:similar', ['cluster' => 1])
            ->expectsOutputToContain('Similar clusters are disabled')
            ->assertFailed();

        $this->command('log-insight:embed')
            ->expectsOutputToContain('Similar clusters are disabled')
            ->assertFailed();
    }
}
