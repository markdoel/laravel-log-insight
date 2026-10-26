<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package uses its own, empty connection here, like an application
 * that has not run the package migrations.
 */
final class InstallationCheckTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.empty', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('log-insight.database.connection', 'empty');
    }

    protected function defineDatabaseMigrations(): void
    {
        // No migrations on purpose.
    }

    #[Test]
    public function analyze_explains_missing_migrations(): void
    {
        $this->command('log-insight:analyze', ['--file' => __DIR__.'/../Fixtures/logs/single/laravel.log'])
            ->expectsOutputToContain('log-insight-migrations')
            ->assertFailed();
    }

    #[Test]
    public function a_dry_run_works_without_migrations(): void
    {
        $this->command('log-insight:analyze', ['--file' => __DIR__.'/../Fixtures/logs/single/laravel.log', '--dry-run' => true])
            ->expectsOutputToContain('Found 5 clusters.')
            ->assertSuccessful();
    }

    #[Test]
    public function report_explains_missing_migrations(): void
    {
        $this->command('log-insight:report')
            ->expectsOutputToContain('php artisan migrate')
            ->assertFailed();
    }
}
