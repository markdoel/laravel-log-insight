<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Fingerprinting;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Data\Fingerprint;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Data\StackFrame;
use MarkusDoell\LogInsight\Fingerprinting\DefaultFingerprinter;
use MarkusDoell\LogInsight\Fingerprinting\FrameMode;
use MarkusDoell\LogInsight\Fingerprinting\MessageNormalizer;
use MarkusDoell\LogInsight\Support\AppPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultFingerprinterTest extends TestCase
{
    private const VENDOR_FILE = '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php';

    private DefaultFingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new DefaultFingerprinter(
            new MessageNormalizer,
            new AppPaths('/var/www/html', ['app/']),
        );
    }

    #[Test]
    public function it_uses_the_throw_location_when_it_is_application_code(): void
    {
        $fingerprint = $this->fingerprinter->fingerprint($this->exception(file: '/var/www/html/app/Services/Billing.php', line: 19));

        $this->assertSame(Fingerprint::TYPE_EXCEPTION, $fingerprint->type);
        $this->assertSame('app/Services/Billing.php:19', $fingerprint->appFrame);
        $this->assertSame('No query results for model [App\Models\Order] {n}', $fingerprint->normalizedMessage);
        $this->assertSame(40, strlen($fingerprint->hash));
    }

    #[Test]
    public function it_skips_vendor_and_internal_frames(): void
    {
        $entry = $this->exception(frames: [
            new StackFrame(0, '/var/www/html/vendor/laravel/framework/src/Illuminate/Support/Traits/ForwardsCalls.php', 23, 'findOrFail()'),
            new StackFrame(1, null, null, '[internal function]: dispatch()'),
            new StackFrame(2, '/var/www/html/app/Http/Controllers/OrderController.php', 42, 'Model::__callStatic()'),
            new StackFrame(3, '/var/www/html/app/Http/Middleware/Locale.php', 12, 'handle()'),
        ]);

        $this->assertSame('app/Http/Controllers/OrderController.php:42', $this->fingerprinter->fingerprint($entry)->appFrame);
    }

    #[Test]
    public function it_has_no_app_frame_when_only_vendor_code_is_involved(): void
    {
        $entry = $this->exception(frames: [new StackFrame(0, self::VENDOR_FILE, 10, 'foo()')]);

        $this->assertNull($this->fingerprinter->fingerprint($entry)->appFrame);
    }

    #[Test]
    public function it_groups_the_same_error_with_different_data(): void
    {
        $first = $this->fingerprinter->fingerprint($this->exception(message: 'No query results for model [App\Models\Order] 48213'));
        $second = $this->fingerprinter->fingerprint($this->exception(message: 'No query results for model [App\Models\Order] 50117'));

        $this->assertSame($first->hash, $second->hash);
    }

    #[Test]
    public function it_separates_errors_from_different_locations(): void
    {
        $first = $this->fingerprinter->fingerprint($this->exception(frames: [$this->appFrame('OrderController', 42)]));
        $second = $this->fingerprinter->fingerprint($this->exception(frames: [$this->appFrame('InvoiceController', 42)]));

        $this->assertNotSame($first->hash, $second->hash);
    }

    #[Test]
    public function it_separates_different_exception_classes(): void
    {
        $first = $this->fingerprinter->fingerprint($this->exception(class: 'RuntimeException'));
        $second = $this->fingerprinter->fingerprint($this->exception(class: 'LogicException'));

        $this->assertNotSame($first->hash, $second->hash);
    }

    #[Test]
    public function it_fingerprints_plain_messages_by_level_and_text(): void
    {
        $first = $this->fingerprinter->fingerprint($this->message('Payment gateway timeout for order 17'));
        $second = $this->fingerprinter->fingerprint($this->message('Payment gateway timeout for order 18'));
        $critical = $this->fingerprinter->fingerprint($this->message('Payment gateway timeout for order 17', LogLevel::Critical));

        $this->assertSame(Fingerprint::TYPE_MESSAGE, $first->type);
        $this->assertNull($first->appFrame);
        $this->assertSame($first->hash, $second->hash);
        $this->assertNotSame($first->hash, $critical->hash);
    }

    #[Test]
    public function it_never_mixes_plain_messages_and_exceptions(): void
    {
        $plain = $this->fingerprinter->fingerprint($this->message('No query results for model [App\Models\Order] 1'));
        $exception = $this->fingerprinter->fingerprint($this->exception(message: 'No query results for model [App\Models\Order] 1', frames: []));

        $this->assertNotSame($plain->hash, $exception->hash);
    }

    #[Test]
    public function line_mode_fingerprints_are_unchanged_since_v1_0_0(): void
    {
        // Hashes produced by v1.0.0. Changing them would split every existing cluster.
        foreach ([$this->fingerprinter, $this->fingerprinter(FrameMode::Line)] as $fingerprinter) {
            $this->assertSame('122310beb5fe73a0324843d217232066796f931c', $fingerprinter->fingerprint($this->exception())->hash);
            $this->assertSame('c022e168dc9b9cf55c85a7f2f3b8ef06bcb6dad7', $fingerprinter->fingerprint($this->exception(frames: []))->hash);
            $this->assertSame('fa5e886ef9c1c045b03c5c6d9720609aa0d6b89a', $fingerprinter->fingerprint($this->message('Payment gateway timeout for order 17'))->hash);
        }
    }

    #[Test]
    public function method_mode_uses_the_function_shown_in_the_next_frame(): void
    {
        $entry = $this->exception(frames: [
            new StackFrame(0, self::VENDOR_FILE, 23, 'Illuminate\Database\Eloquent\Builder->findOrFail(48213)'),
            new StackFrame(1, '/var/www/html/app/Http/Controllers/OrderController.php', 42, "Illuminate\Database\Eloquent\Model::__callStatic('findOrFail', Array)"),
            new StackFrame(2, '/var/www/html/vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 46, 'App\Http\Controllers\OrderController->show(48213)'),
            new StackFrame(3, null, null, '{main}'),
        ]);

        $this->assertSame(
            'app/Http/Controllers/OrderController.php::App\Http\Controllers\OrderController::show',
            $this->fingerprinter(FrameMode::Method)->fingerprint($entry)->appFrame,
        );
    }

    #[Test]
    public function method_mode_uses_the_first_frame_for_the_throw_location(): void
    {
        $entry = $this->exception(file: '/var/www/html/app/Services/InvoiceRenderer.php', line: 19, frames: [
            new StackFrame(0, '/var/www/html/app/Jobs/SendInvoiceJob.php', 38, 'App\Services\InvoiceRenderer->render(NULL)'),
            new StackFrame(1, null, null, '{main}'),
        ]);

        $this->assertSame(
            'app/Services/InvoiceRenderer.php::App\Services\InvoiceRenderer::render',
            $this->fingerprinter(FrameMode::Method)->fingerprint($entry)->appFrame,
        );
    }

    #[Test]
    public function method_mode_keeps_the_cluster_when_only_lines_shift(): void
    {
        $fingerprinter = $this->fingerprinter(FrameMode::Method);

        $before = $fingerprinter->fingerprint($this->exception(frames: $this->controllerFrames(42, 'show')));
        $after = $fingerprinter->fingerprint($this->exception(frames: $this->controllerFrames(57, 'show')));
        $otherMethod = $fingerprinter->fingerprint($this->exception(frames: $this->controllerFrames(42, 'update')));

        $this->assertSame($before->hash, $after->hash);
        $this->assertNotSame($before->hash, $otherMethod->hash);
        $this->assertNotSame($before->hash, $this->fingerprinter->fingerprint($this->exception(frames: $this->controllerFrames(42, 'show')))->hash);
    }

    #[Test]
    public function method_mode_falls_back_to_the_line_when_the_function_is_unknown(): void
    {
        $fingerprinter = $this->fingerprinter(FrameMode::Method);

        foreach ([
            [$this->appFrame('OrderController', 42)],
            [$this->appFrame('OrderController', 42), new StackFrame(1, null, null, '{main}')],
            [$this->appFrame('OrderController', 42), new StackFrame(1, self::VENDOR_FILE, 10, 'not a call')],
        ] as $frames) {
            $entry = $this->exception(frames: $frames);

            $this->assertSame('app/Http/Controllers/OrderController.php:42', $fingerprinter->fingerprint($entry)->appFrame);
            $this->assertSame($this->fingerprinter->fingerprint($entry)->hash, $fingerprinter->fingerprint($entry)->hash);
        }
    }

    #[Test]
    #[DataProvider('calls')]
    public function method_mode_reads_the_function_from_the_call(string $call, string $function): void
    {
        $entry = $this->exception(frames: [
            $this->appFrame('OrderController', 42),
            new StackFrame(1, self::VENDOR_FILE, 10, $call),
        ]);

        $this->assertSame(
            'app/Http/Controllers/OrderController.php::'.$function,
            $this->fingerprinter(FrameMode::Method)->fingerprint($entry)->appFrame,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function calls(): iterable
    {
        yield 'instance method' => ['App\Foo->bar(Object(App\Baz), \'x\')', 'App\Foo::bar'];
        yield 'static method' => ['App\Foo::bar()', 'App\Foo::bar'];
        yield 'function' => ['report_failure(Array)', 'report_failure'];
        yield 'internal caller' => ['[internal function]: App\Foo->bar()', 'App\Foo::bar'];
        yield 'closure' => ['App\Foo->{closure}(1)', 'App\Foo::{closure}'];
        yield 'namespaced closure' => ['Illuminate\Pipeline\Pipeline->Illuminate\Pipeline\{closure}(Object(Illuminate\Http\Request))', 'Illuminate\Pipeline\Pipeline::Illuminate\Pipeline\{closure}'];
        yield 'PHP 8.4 closure' => ['{closure:App\Foo::bar():12}(1)', '{closure}'];
        yield 'nested PHP 8.4 closure' => ['{closure:{closure:App\Foo::bar():12}:14}()', '{closure}'];
        yield 'anonymous class' => ['class@anonymous/var/www/html/app/Foo.php:12$0->handle()', 'class@anonymous::handle'];
    }

    /**
     * @param  list<StackFrame>|null  $frames
     */
    private function exception(
        string $class = 'Illuminate\Database\Eloquent\ModelNotFoundException',
        string $message = 'No query results for model [App\Models\Order] 48213',
        string $file = self::VENDOR_FILE,
        int $line = 780,
        ?array $frames = null,
    ): LogEntry {
        return new LogEntry(
            timestamp: CarbonImmutable::parse('2026-10-25 09:30:55'),
            channel: 'production',
            level: LogLevel::Error,
            message: $message,
            exceptionClass: $class,
            file: $file,
            line: $line,
            stackTrace: '#0 {main}',
            frames: $frames ?? [$this->appFrame('OrderController', 42)],
        );
    }

    /**
     * @return list<StackFrame>
     */
    private function controllerFrames(int $line, string $method): array
    {
        return [
            new StackFrame(0, '/var/www/html/app/Http/Controllers/OrderController.php', $line, 'App\\Models\\Order::findOrFail(1)'),
            new StackFrame(1, self::VENDOR_FILE, 46, "App\\Http\\Controllers\\OrderController->{$method}(1)"),
        ];
    }

    private function fingerprinter(FrameMode $mode): DefaultFingerprinter
    {
        return new DefaultFingerprinter(new MessageNormalizer, new AppPaths('/var/www/html', ['app/']), $mode);
    }

    private function message(string $message, LogLevel $level = LogLevel::Error): LogEntry
    {
        return new LogEntry(
            timestamp: CarbonImmutable::parse('2026-10-25 09:30:55'),
            channel: 'production',
            level: $level,
            message: $message,
        );
    }

    private function appFrame(string $controller, int $line): StackFrame
    {
        return new StackFrame(0, "/var/www/html/app/Http/Controllers/{$controller}.php", $line, 'show()');
    }
}
