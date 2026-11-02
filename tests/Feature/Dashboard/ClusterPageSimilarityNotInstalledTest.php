<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Livewire\Livewire;
use MarkusDoell\LogInsight\Dashboard\ClusterPage;
use MarkusDoell\LogInsight\Tests\Concerns\FakesVoyage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Similar clusters are enabled, but the embeddings migration has not been run.
 */
final class ClusterPageSimilarityNotInstalledTest extends DashboardTestCase
{
    use FakesVoyage;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    #[Test]
    public function the_page_still_renders_and_explains_the_missing_migration(): void
    {
        $this->inLocalEnvironment();
        $this->enableSimilarity();
        $cluster = $this->makeCluster();

        Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id])
            ->assertSee('No query results for model [App\Models\Order] {n}')
            ->assertSee('Similar clusters')
            ->assertSee('--tag=log-insight-similarity-migrations');
    }
}
