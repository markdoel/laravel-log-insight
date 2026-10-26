<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Round trip through Laravel's real logger, so the parser is verified
 * against the exact output of the installed Monolog version.
 */
final class RealLogFormatTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir().'/log-insight-real-'.bin2hex(random_bytes(4)).'.log';

        config()->set('logging.channels.roundtrip', [
            'driver' => 'single',
            'path' => $this->logFile,
            'level' => 'debug',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    #[Test]
    public function it_parses_exceptions_written_by_laravels_logger(): void
    {
        $previous = new InvalidArgumentException('Inner "quoted" problem');
        $exception = new RuntimeException("Outer failure for user 42\nsecond line", 7, $previous);

        Log::channel('roundtrip')->error($exception->getMessage(), ['exception' => $exception, 'userId' => 42]);
        Log::channel('roundtrip')->info('Plain message', ['key' => 'value']);

        $entries = $this->parseLog();

        $this->assertCount(2, $entries);

        $entry = $entries[0];
        $this->assertSame(LogLevel::Error, $entry->level);
        $this->assertSame(RuntimeException::class, $entry->exceptionClass);
        $this->assertSame("Outer failure for user 42\nsecond line", $entry->message);
        $this->assertSame(__FILE__, $entry->file);
        $this->assertSame($exception->getLine(), $entry->line);
        $this->assertNotNull($entry->stackTrace);
        $this->assertStringContainsString('[previous exception]', $entry->stackTrace);
        $this->assertStringContainsString('InvalidArgumentException', $entry->stackTrace);
        $this->assertNotEmpty($entry->frames);
        $this->assertSame(0, $entry->frames[0]->index);

        $this->assertSame('Plain message', $entries[1]->message);
        $this->assertSame(['key' => 'value'], $entries[1]->context);
    }

    /**
     * @return list<LogEntry>
     */
    private function parseLog(): array
    {
        return iterator_to_array(resolve(LogParser::class)->parseFile($this->logFile), false);
    }
}
