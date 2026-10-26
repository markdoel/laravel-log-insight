<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MarkusDoell\LogInsight\Commands\AnalyzeCommand;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\MonologLogParser;

final class LogInsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-insight.php', 'log-insight');

        $this->app->singleton(LogParser::class, MonologLogParser::class);

        $this->app->singleton(LogFileLocator::class, static function (Application $app): LogFileLocator {
            $config = $app->make(Repository::class);

            return new LogFileLocator(
                directory: self::configString($config, 'log-insight.logs.path', $app->storagePath('logs')),
                pattern: self::configString($config, 'log-insight.logs.pattern', 'laravel*.log'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/log-insight.php' => config_path('log-insight.php'),
            ], 'log-insight-config');

            $this->commands([
                AnalyzeCommand::class,
            ]);
        }
    }

    private static function configString(Repository $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
