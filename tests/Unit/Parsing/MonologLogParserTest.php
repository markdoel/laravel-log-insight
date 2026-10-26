<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Parsing;

use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Exceptions\LogFileNotFound;
use MarkusDoell\LogInsight\Parsing\MonologLogParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonologLogParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/logs';

    private MonologLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MonologLogParser;
    }

    #[Test]
    public function it_parses_every_entry_of_a_single_log_file(): void
    {
        $entries = $this->parseFixture('single/laravel.log');

        $this->assertCount(8, $entries);
        $this->assertSame(
            ['info', 'warning', 'error', 'error', 'error', 'critical', 'error', 'error'],
            array_map(static fn (LogEntry $entry): string => $entry->level->value, $entries),
        );
    }

    #[Test]
    public function it_ignores_lines_before_the_first_entry(): void
    {
        $entries = $this->parseFixture('single/laravel.log');

        $this->assertSame('Scheduled command "reports:daily" finished', $entries[0]->message);
    }

    #[Test]
    public function it_extracts_header_fields_and_context_of_plain_entries(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[2];

        $this->assertSame('2026-10-25 09:02:17', $entry->timestamp->format('Y-m-d H:i:s'));
        $this->assertSame('production', $entry->channel);
        $this->assertSame(LogLevel::Error, $entry->level);
        $this->assertSame('Payment gateway returned an unexpected response', $entry->message);
        $this->assertSame(['order_id' => 48213, 'status' => 502], $entry->context);
        $this->assertFalse($entry->hasException());
        $this->assertNull($entry->stackTrace);
        $this->assertSame([], $entry->frames);
    }

    #[Test]
    public function it_extracts_exception_class_message_file_and_line(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[3];

        $this->assertSame('Illuminate\Database\Eloquent\ModelNotFoundException', $entry->exceptionClass);
        $this->assertSame('No query results for model [App\Models\Order] 48213', $entry->message);
        $this->assertSame('/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', $entry->file);
        $this->assertSame(780, $entry->line);
    }

    #[Test]
    public function it_merges_a_multi_line_stack_trace_into_frames(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[3];

        $this->assertNotNull($entry->stackTrace);
        $this->assertStringStartsWith('#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Support/Traits/ForwardsCalls.php(23)', $entry->stackTrace);
        $this->assertStringEndsWith('#6 {main}', $entry->stackTrace);
        $this->assertStringNotContainsString('"}', $entry->stackTrace);

        $this->assertCount(7, $entry->frames);

        $appFrame = $entry->frames[2];
        $this->assertSame(2, $appFrame->index);
        $this->assertSame('/var/www/html/app/Http/Controllers/OrderController.php', $appFrame->file);
        $this->assertSame(42, $appFrame->line);
        $this->assertSame("Illuminate\Database\Eloquent\Model::__callStatic('findOrFail', Array)", $appFrame->call);

        $internal = $entry->frames[4];
        $this->assertTrue($internal->isInternal());
        $this->assertNull($internal->line);
        $this->assertStringStartsWith('[internal function]: ', $internal->call);

        $this->assertSame('{main}', $entry->frames[6]->call);
    }

    #[Test]
    public function it_keeps_previous_exceptions_in_the_trace_but_not_in_the_frames(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[4];

        $this->assertSame('Illuminate\Database\UniqueConstraintViolationException', $entry->exceptionClass);
        $this->assertSame(822, $entry->line);
        $this->assertNotNull($entry->stackTrace);
        $this->assertStringContainsString('[previous exception] [object] (PDOException(code: 23000)', $entry->stackTrace);
        $this->assertCount(4, $entry->frames);
        $this->assertSame('/var/www/html/app/Services/RegistrationService.php', $entry->frames[1]->file);
    }

    #[Test]
    public function it_handles_messages_containing_paths_and_parentheses(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[5];

        $this->assertSame(LogLevel::Critical, $entry->level);
        $this->assertSame('TypeError', $entry->exceptionClass);
        $this->assertSame(
            'App\Services\InvoiceRenderer::render(): Argument #1 ($invoice) must be of type App\Models\Invoice, null given, called in /var/www/html/app/Jobs/SendInvoiceJob.php on line 38',
            $entry->message,
        );
        $this->assertSame('/var/www/html/app/Services/InvoiceRenderer.php', $entry->file);
        $this->assertSame(19, $entry->line);
    }

    #[Test]
    public function it_merges_multi_line_messages_of_plain_entries(): void
    {
        $entry = $this->parseFixture('single/laravel.log')[6];

        $this->assertSame("Import failed for row 12\nExpected column \"sku\" to be present\nGot: name, price", $entry->message);
        $this->assertSame(['file' => 'products.csv'], $entry->context);
    }

    #[Test]
    public function it_parses_iso_timestamps_and_multi_line_exception_messages(): void
    {
        $entries = $this->parseFixture('daily/laravel-2026-10-24.log');

        $this->assertCount(2, $entries);

        $entry = $entries[1];
        $this->assertSame('2026-10-24T22:15:03.123456+02:00', $entry->timestamp->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('staging', $entry->channel);
        $this->assertSame('RuntimeException', $entry->exceptionClass);
        $this->assertSame("Could not connect to cache server at 10.0.0.12:6379\nConnection refused", $entry->message);
        $this->assertSame('/var/www/html/app/Support/CacheWarmer.php', $entry->file);
        $this->assertSame(54, $entry->line);
        $this->assertCount(3, $entry->frames);
    }

    #[Test]
    public function it_parses_exceptions_without_a_stack_trace(): void
    {
        $entry = $this->parseFixture('daily/laravel-2026-10-25.log')[1];

        $this->assertSame('ErrorException', $entry->exceptionClass);
        $this->assertSame('Undefined array key "currency"', $entry->message);
        $this->assertSame('/var/www/html/app/Support/PriceFormatter.php', $entry->file);
        $this->assertSame(22, $entry->line);
        $this->assertNull($entry->stackTrace);
        $this->assertSame([], $entry->frames);
    }

    #[Test]
    public function it_handles_windows_line_endings_and_trailing_extra(): void
    {
        $entries = $this->parse([
            "[2026-10-25 10:00:00] local.NOTICE: First {\"a\":1} []\r\n",
            "[2026-10-25 10:00:01] local.DEBUG: Second\r\n",
        ]);

        $this->assertCount(2, $entries);
        $this->assertSame(LogLevel::Notice, $entries[0]->level);
        $this->assertSame('First', $entries[0]->message);
        $this->assertSame(['a' => 1], $entries[0]->context);
        $this->assertSame('Second', $entries[1]->message);
    }

    #[Test]
    public function it_keeps_braces_that_are_not_json_context_in_the_message(): void
    {
        $entries = $this->parse(['[2026-10-25 10:00:00] local.INFO: Template {name} not found']);

        $this->assertSame('Template {name} not found', $entries[0]->message);
        $this->assertSame([], $entries[0]->context);
    }

    #[Test]
    public function it_treats_lines_with_unknown_levels_as_continuation(): void
    {
        $entries = $this->parse([
            '[2026-10-25 10:00:00] local.ERROR: Outer',
            '[2026-10-25 10:00:01] local.VERBOSE: not a real level',
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame("Outer\n[2026-10-25 10:00:01] local.VERBOSE: not a real level", $entries[0]->message);
    }

    #[Test]
    public function it_returns_nothing_for_empty_input(): void
    {
        $this->assertSame([], $this->parse([]));
        $this->assertSame([], $this->parse(['', 'garbage without header']));
    }

    #[Test]
    public function it_throws_for_missing_files(): void
    {
        $this->expectException(LogFileNotFound::class);

        iterator_to_array($this->parser->parseFile(self::FIXTURES.'/does-not-exist.log'));
    }

    /**
     * @return list<LogEntry>
     */
    private function parseFixture(string $name): array
    {
        return iterator_to_array($this->parser->parseFile(self::FIXTURES.'/'.$name), false);
    }

    /**
     * @param  list<string>  $lines
     * @return list<LogEntry>
     */
    private function parse(array $lines): array
    {
        return iterator_to_array($this->parser->parse($lines), false);
    }
}
