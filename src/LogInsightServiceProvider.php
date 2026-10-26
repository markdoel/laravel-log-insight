<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MarkusDoell\LogInsight\Analysis\LogAnalyzer;
use MarkusDoell\LogInsight\Commands\AnalyzeCommand;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Fingerprinting\DefaultFingerprinter;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\MonologLogParser;
use MarkusDoell\LogInsight\Redaction\PatternRedactor;
use MarkusDoell\LogInsight\Support\AppPaths;

final class LogInsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-insight.php', 'log-insight');

        $this->app->singleton(LogParser::class, MonologLogParser::class);
        $this->app->singleton(Fingerprinter::class, DefaultFingerprinter::class);

        $this->app->singleton(LogFileLocator::class, static function (Application $app): LogFileLocator {
            $config = $app->make(Repository::class);

            return new LogFileLocator(
                directory: self::configString($config, 'log-insight.logs.path', $app->storagePath('logs')),
                pattern: self::configString($config, 'log-insight.logs.pattern', 'laravel*.log'),
            );
        });

        $this->app->singleton(AppPaths::class, static function (Application $app): AppPaths {
            $config = $app->make(Repository::class);
            $appPaths = $config->get('log-insight.fingerprint.app_paths');

            return new AppPaths(
                basePath: self::configString($config, 'log-insight.context.base_path', $app->basePath()),
                appPaths: is_array($appPaths) ? array_values(array_filter($appPaths, is_string(...))) : ['app/'],
            );
        });

        $this->app->singleton(Redactor::class, static function (Application $app): PatternRedactor {
            $config = $app->make(Repository::class);
            $patterns = [];

            foreach ((array) $config->get('log-insight.redaction.patterns', []) as $pattern => $replacement) {
                if (is_string($pattern) && is_string($replacement)) {
                    $patterns[$pattern] = $replacement;
                }
            }

            return new PatternRedactor(
                customPatterns: $patterns,
                redactTraceArguments: (bool) $config->get('log-insight.redaction.trace_arguments', true),
            );
        });

        $this->app->when(LogAnalyzer::class)
            ->needs(LogLevel::class)
            ->give(static fn (Application $app): LogLevel => LogLevel::fromName(
                self::configString($app->make(Repository::class), 'log-insight.clustering.min_level', 'error'),
            ) ?? LogLevel::Error);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/log-insight.php' => config_path('log-insight.php'),
            ], 'log-insight-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'log-insight-migrations');

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
