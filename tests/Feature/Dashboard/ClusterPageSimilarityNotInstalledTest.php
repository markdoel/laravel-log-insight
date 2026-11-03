<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use MarkusDoell\LogInsight\Dashboard\ClusterPage;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Similar clusters are enabled, but the embeddings migration has not been
 * run. Not a DashboardTestCase: RefreshDatabase would migrate the shared
 * test database with only the original migration.
 */
final class ClusterPageSimilarityNotInstalledTest extends TestCase
{
    use FakesVoyage;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('log-insight.dashboard.enabled', true);
        $app['config']->set('database.connections.without_similarity', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('log-insight.database.connection', 'without_similarity');
    }

    /**
     * Only the original migration, on a connection of its own.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            '--database' => 'without_similarity',
            '--path' => (string) realpath(__DIR__.'/../../../database/migrations'),
            '--realpath' => true,
        ]);
    }

    #[Test]
    public function the_page_still_renders_and_explains_the_missing_migration(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $this->enableSimilarity();
        $cluster = $this->makeCluster();

        Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id])
            ->assertSee('No query results for model [App\Models\Order] {n}')
            ->assertSee('Similar clusters')
            ->assertSee('--tag=log-insight-similarity-migrations');
    }
}
