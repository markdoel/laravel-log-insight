<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Ai;

use MarkusDoell\LogInsight\Ai\AnalysisPrompt;
use MarkusDoell\LogInsight\Ai\AnalysisResponseParser;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Exceptions\InvalidAnalysisResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalysisResponseParserTest extends TestCase
{
    private const VALID = [
        'root_cause' => ' The order was deleted. ',
        'suggested_fix' => 'Handle the missing order.',
        'affected_files' => ['app/Http/Controllers/OrderController.php', 'app/Http/Controllers/OrderController.php', ''],
        'severity' => 'High',
        'confidence' => 0.856,
    ];

    #[Test]
    public function it_parses_a_valid_answer(): void
    {
        $data = (new AnalysisResponseParser)->parse((string) json_encode(self::VALID));

        $this->assertSame('The order was deleted.', $data->rootCause);
        $this->assertSame('Handle the missing order.', $data->suggestedFix);
        $this->assertSame(['app/Http/Controllers/OrderController.php'], $data->affectedFiles);
        $this->assertSame(Severity::High, $data->severity);
        $this->assertSame(0.86, $data->confidence);
    }

    #[Test]
    public function it_accepts_integer_confidence_and_empty_file_lists(): void
    {
        $data = (new AnalysisResponseParser)->parse((string) json_encode([...self::VALID, 'confidence' => 1, 'affected_files' => []]));

        $this->assertSame(1.0, $data->confidence);
        $this->assertSame([], $data->affectedFiles);
    }

    #[Test]
    public function it_tolerates_a_markdown_code_fence(): void
    {
        $data = (new AnalysisResponseParser)->parse("```json\n".json_encode(self::VALID)."\n```");

        $this->assertSame(Severity::High, $data->severity);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAnswers(): iterable
    {
        $encode = static fn (array $data): string => (string) json_encode($data);

        yield 'empty' => ['', 'empty'];
        yield 'not json' => ['The root cause is a deleted order.', 'not valid JSON'];
        yield 'truncated json' => ['{"root_cause": "The order', 'not valid JSON'];
        yield 'json list' => ['[1, 2]', 'JSON object'];
        yield 'missing root cause' => [$encode(array_diff_key(self::VALID, ['root_cause' => true])), '"root_cause"'];
        yield 'blank fix' => [$encode([...self::VALID, 'suggested_fix' => '  ']), '"suggested_fix"'];
        yield 'files not a list' => [$encode([...self::VALID, 'affected_files' => 'app/Models/Order.php']), '"affected_files"'];
        yield 'files with numbers' => [$encode([...self::VALID, 'affected_files' => [42]]), '"affected_files"'];
        yield 'unknown severity' => [$encode([...self::VALID, 'severity' => 'urgent']), '"severity"'];
        yield 'confidence as text' => [$encode([...self::VALID, 'confidence' => 'high']), '"confidence" must be a number'];
        yield 'confidence in percent' => [$encode([...self::VALID, 'confidence' => 85]), 'between 0 and 1'];
        yield 'negative confidence' => [$encode([...self::VALID, 'confidence' => -0.1]), 'between 0 and 1'];
    }

    #[Test]
    #[DataProvider('invalidAnswers')]
    public function it_rejects_invalid_answers(string $answer, string $reason): void
    {
        $this->expectException(InvalidAnalysisResponse::class);
        $this->expectExceptionMessage($reason);

        (new AnalysisResponseParser)->parse($answer);
    }

    #[Test]
    public function the_schema_requires_every_field_and_allows_no_others(): void
    {
        $schema = AnalysisPrompt::schema();

        $this->assertSame(['root_cause', 'suggested_fix', 'affected_files', 'severity', 'confidence'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $properties = $schema['properties'] ?? null;
        $this->assertIsArray($properties);
        $severity = $properties['severity'] ?? null;
        $this->assertIsArray($severity);
        $this->assertSame(['low', 'medium', 'high', 'critical'], $severity['enum'] ?? null);
    }
}
