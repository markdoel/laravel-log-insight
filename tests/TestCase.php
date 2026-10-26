<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests;

use Illuminate\Testing\PendingCommand;
use MarkusDoell\LogInsight\LogInsightServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LogInsightServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Typed wrapper around artisan(): with mocked console output it always
     * returns a PendingCommand.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            $this->fail('Console output mocking must be enabled to assert on commands.');
        }

        return $pending;
    }
}
