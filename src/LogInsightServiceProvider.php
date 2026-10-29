<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MarkusDoell\LogInsight\Ai\AnalysisScheduler;
use MarkusDoell\LogInsight\Ai\ClusterContextBuilder;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Analysis\LogAnalyzer;
use MarkusDoell\LogInsight\Commands\AnalyzeCommand;
use MarkusDoell\LogInsight\Commands\ReportCommand;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Dashboard\ClusterList;
use MarkusDoell\LogInsight\Dashboard\DashboardController;
use MarkusDoell\LogInsight\Dashboard\LivewireSupport;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Fingerprinting\DefaultFingerprinter;
use MarkusDoell\LogInsight\Fingerprinting\FrameMode;
use MarkusDoell\LogInsight\Listeners\SendSevereClusterNotification;
use MarkusDoell\LogInsight\Llm\AnthropicClient;
use MarkusDoell\LogInsight\Notifications\ClusterNotifier;
use MarkusDoell\LogInsight\Notifications\SlackChannelSupport;
use MarkusDoell\LogInsight\Parsing\LogFileLocator;
use MarkusDoell\LogInsight\Parsing\MonologLogParser;
use MarkusDoell\LogInsight\Redaction\PatternRedactor;
use MarkusDoell\LogInsight\Support\AppPaths;
use Psr\Log\LoggerInterface;

final class LogInsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-insight.php', 'log-insight');

        $this->app->singleton(LogParser::class, MonologLogParser::class);
        $this->app->singleton(Fingerprinter::class, DefaultFingerprinter::class);

        $this->app->when(DefaultFingerprinter::class)
            ->needs(FrameMode::class)
            ->give(static function (Application $app): FrameMode {
                $mode = self::configString($app->make(Repository::class), 'log-insight.fingerprint.frame', 'line');

                return FrameMode::tryFrom(strtolower($mode))
                    ?? throw new LogInsightException("Unsupported fingerprint frame mode [{$mode}]. Use \"line\" or \"method\".");
            });

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

        $this->app->singleton(LlmClient::class, static function (Application $app): LlmClient {
            $config = $app->make(Repository::class);
            $driver = self::configString($config, 'log-insight.llm.driver', 'anthropic');

            return match ($driver) {
                'anthropic' => new AnthropicClient(
                    http: $app->make(HttpFactory::class),
                    apiKey: self::configNullableString($config, 'log-insight.llm.anthropic.api_key'),
                    model: self::configString($config, 'log-insight.llm.anthropic.model', 'claude-opus-5-5'),
                    baseUrl: self::configString($config, 'log-insight.llm.anthropic.base_url', 'https://api.anthropic.com'),
                    apiVersion: self::configString($config, 'log-insight.llm.anthropic.api_version', '2026-10-21'),
                    timeout: self::configInt($config, 'log-insight.llm.anthropic.timeout', 300),
                    maxTokens: self::configInt($config, 'log-insight.llm.anthropic.max_tokens', 16000),
                    effort: self::configNullableString($config, 'log-insight.llm.anthropic.effort'),
                    fallbacks: (bool) $config->get('log-insight.llm.anthropic.fallbacks', true),
                ),
                default => throw new LogInsightException("Unsupported LLM driver [{$driver}]."),
            };
        });

        $this->app->singleton(ClusterContextBuilder::class, static function (Application $app): ClusterContextBuilder {
            $config = $app->make(Repository::class);

            return new ClusterContextBuilder(
                redactor: $app->make(Redactor::class),
                appPaths: $app->make(AppPaths::class),
                contextLines: self::configInt($config, 'log-insight.context.lines', 15),
                maxTraceLines: self::configInt($config, 'log-insight.context.max_trace_lines', 60),
            );
        });

        $this->app->singleton(AnalysisScheduler::class, static function (Application $app): AnalysisScheduler {
            $config = $app->make(Repository::class);

            return new AnalysisScheduler(
                client: $app->make(LlmClient::class),
                bus: $app->make(BusDispatcher::class),
                enabled: (bool) $config->get('log-insight.analysis.enabled', true),
                connection: self::configNullableString($config, 'log-insight.queue.connection'),
                queue: self::configNullableString($config, 'log-insight.queue.name'),
                requestTimeout: self::configInt($config, 'log-insight.llm.anthropic.timeout', 300),
            );
        });

        $this->app->singletonIf(LivewireSupport::class, static fn (): LivewireSupport => LivewireSupport::detect());

        $this->app->singleton(SlackChannelSupport::class, static fn (): SlackChannelSupport => SlackChannelSupport::detect());

        $this->app->singleton(ClusterNotifier::class, static function (Application $app): ClusterNotifier {
            $config = $app->make(Repository::class);
            $severity = self::configString($config, 'log-insight.notifications.min_severity', 'critical');
            $recipients = $config->get('log-insight.notifications.mail.to');

            return new ClusterNotifier(
                notifications: $app->make(NotificationDispatcher::class),
                redactor: $app->make(Redactor::class),
                logger: $app->make(LoggerInterface::class),
                slack: $app->make(SlackChannelSupport::class),
                minSeverity: Severity::tryFrom(strtolower($severity))
                    ?? throw new LogInsightException("Unsupported notification severity [{$severity}]. Use one of: ".implode(', ', Severity::values()).'.'),
                mailRecipients: is_array($recipients) ? array_values(array_filter($recipients, static fn (mixed $to): bool => is_string($to) && $to !== '')) : [],
                slackRoute: self::configNullableString($config, 'log-insight.notifications.slack.webhook_url')
                    ?? self::configNullableString($config, 'log-insight.notifications.slack.channel'),
                connection: self::configNullableString($config, 'log-insight.queue.connection'),
                queue: self::configNullableString($config, 'log-insight.queue.name'),
            );
        });
    }

    public function boot(): void
    {
        $config = $this->app->make(Repository::class);

        if ((bool) $config->get('log-insight.notifications.enabled', false)) {
            $this->app->make(EventDispatcher::class)->listen(AnalysisCompleted::class, SendSevereClusterNotification::class);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'log-insight');

        if ((bool) $config->get('log-insight.dashboard.enabled', false)) {
            $this->bootDashboard($config);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/log-insight.php' => config_path('log-insight.php'),
            ], 'log-insight-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'log-insight-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/log-insight'),
            ], 'log-insight-views');

            $this->commands([
                AnalyzeCommand::class,
                ReportCommand::class,
            ]);
        }
    }

    private function bootDashboard(Repository $config): void
    {
        $gate = $this->app->make(Gate::class);

        // Like Horizon and Telescope: local only, until the application defines the gate.
        if (! $gate->has('viewLogInsight')) {
            $app = $this->app;
            $gate->define('viewLogInsight', static fn (mixed $user = null): bool => $app->environment('local') === true);
        }

        if ($this->app->make(LivewireSupport::class)->installed) {
            Livewire::component(ClusterList::NAME, ClusterList::class);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        $middleware = $config->get('log-insight.dashboard.middleware', ['web']);

        $this->app->make(Router::class)
            ->middleware([
                ...(is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : ['web']),
                'can:viewLogInsight',
            ])
            ->prefix(trim(self::configString($config, 'log-insight.dashboard.path', 'log-insight'), '/'))
            ->name('log-insight.')
            ->group(static function (Router $router): void {
                $router->get('/', DashboardController::class)->name('dashboard');
            });
    }

    private static function configNullableString(Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function configInt(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private static function configString(Repository $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
