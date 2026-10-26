<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight;

use Illuminate\Support\ServiceProvider;

final class LogInsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-insight.php', 'log-insight');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/log-insight.php' => config_path('log-insight.php'),
            ], 'log-insight-config');
        }
    }
}
