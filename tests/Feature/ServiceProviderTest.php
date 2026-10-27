<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\ServiceProvider;
use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Data\StackFrame;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\LogInsightServiceProvider;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_config(): void
    {
        $this->assertSame('anthropic', config('log-insight.llm.driver'));
        $this->assertSame(15, config('log-insight.context.lines'));
    }

    #[Test]
    public function it_registers_the_config_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(LogInsightServiceProvider::class, 'log-insight-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/log-insight.php', (string) array_key_first($paths));
    }

    #[Test]
    public function it_registers_the_migrations_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(LogInsightServiceProvider::class, 'log-insight-migrations');

        $this->assertCount(1, $paths);
        $this->assertFileExists(array_key_first($paths).'/create_log_insight_tables.php');
    }

    #[Test]
    public function it_fingerprints_by_line_by_default(): void
    {
        $this->assertSame('line', config('log-insight.fingerprint.frame'));
        $this->assertSame('app/Http/Controllers/OrderController.php:42', resolve(Fingerprinter::class)->fingerprint($this->entry())->appFrame);
    }

    #[Test]
    public function it_fingerprints_by_method_when_configured(): void
    {
        config()->set('log-insight.fingerprint.frame', 'method');

        $this->assertSame(
            'app/Http/Controllers/OrderController.php::App\Http\Controllers\OrderController::show',
            resolve(Fingerprinter::class)->fingerprint($this->entry())->appFrame,
        );
    }

    #[Test]
    public function it_rejects_an_unknown_frame_mode(): void
    {
        config()->set('log-insight.fingerprint.frame', 'function');

        $this->expectException(LogInsightException::class);
        $this->expectExceptionMessage('Unsupported fingerprint frame mode [function]');

        resolve(Fingerprinter::class);
    }

    private function entry(): LogEntry
    {
        return new LogEntry(
            timestamp: CarbonImmutable::parse('2026-10-25 09:30:55'),
            channel: 'production',
            level: LogLevel::Error,
            message: 'No query results for model [App\Models\Order] 48213',
            exceptionClass: 'Illuminate\Database\Eloquent\ModelNotFoundException',
            file: '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php',
            line: 780,
            frames: [
                new StackFrame(0, '/var/www/html/app/Http/Controllers/OrderController.php', 42, "Illuminate\Database\Eloquent\Model::__callStatic('findOrFail', Array)"),
                new StackFrame(1, '/var/www/html/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 46, 'App\Http\Controllers\OrderController->show(48213)'),
            ],
        );
    }
}
