<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use MarkusDoell\LogInsight\Tests\Concerns\CreatesClusters;
use MarkusDoell\LogInsight\Tests\TestCase;

abstract class DashboardTestCase extends TestCase
{
    use CreatesClusters;
    use RefreshDatabase;

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
    }

    /**
     * Grants access the way the default gate does on a developer machine.
     */
    protected function inLocalEnvironment(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
    }
}
