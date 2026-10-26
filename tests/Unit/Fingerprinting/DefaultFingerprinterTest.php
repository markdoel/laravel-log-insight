<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Fingerprinting;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Data\Fingerprint;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Data\StackFrame;
use MarkusDoell\LogInsight\Fingerprinting\DefaultFingerprinter;
use MarkusDoell\LogInsight\Fingerprinting\MessageNormalizer;
use MarkusDoell\LogInsight\Support\AppPaths;
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
