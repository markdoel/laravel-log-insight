<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use MarkusDoell\LogInsight\Contracts\LogParser;
use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Exceptions\InvalidRedactionPattern;
use MarkusDoell\LogInsight\Redaction\PatternRedactor;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RedactionTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/logs';

    #[Test]
    public function the_container_provides_the_pattern_redactor(): void
    {
        $this->assertInstanceOf(PatternRedactor::class, resolve(Redactor::class));
    }

    #[Test]
    public function custom_patterns_come_from_the_config(): void
    {
        config()->set('log-insight.redaction.patterns', ['/\bSKU-\d+\b/' => '[SKU]']);

        $this->assertSame('Product [SKU] is sold out', resolve(Redactor::class)->redact('Product SKU-4711 is sold out'));
    }

    #[Test]
    public function trace_argument_redaction_follows_the_config(): void
    {
        config()->set('log-insight.redaction.trace_arguments', false);
        $frame = "#0 /var/www/html/app/Models/Order.php(12): App\\Models\\Order::findOrFail('48213')";

        $this->assertSame($frame, resolve(Redactor::class)->redact($frame));
    }

    #[Test]
    public function an_invalid_custom_pattern_fails_loudly(): void
    {
        config()->set('log-insight.redaction.patterns', ['not a regex' => 'x']);

        $this->expectException(InvalidRedactionPattern::class);

        resolve(Redactor::class);
    }

    #[Test]
    public function no_email_or_ip_address_survives_in_the_fixture_logs(): void
    {
        $redactor = resolve(Redactor::class);
        $parser = resolve(LogParser::class);

        foreach (glob(self::FIXTURES.'/*/*.log') ?: [] as $file) {
            foreach ($parser->parseFile($file) as $entry) {
                $text = $redactor->redact($entry->message."\n".($entry->stackTrace ?? ''));

                $this->assertDoesNotMatchRegularExpression('/[\w.+\-]+@[\w\-]+\.[\w.]+/', $text);
                $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $text);
            }
        }
    }
}
