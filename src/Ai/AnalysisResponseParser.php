<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

use JsonException;
use MarkusDoell\LogInsight\Exceptions\InvalidAnalysisResponse;

/**
 * Validates the model's JSON answer against the analysis contract.
 */
final class AnalysisResponseParser
{
    public function parse(string $text): AnalysisData
    {
        $text = $this->stripCodeFence(trim($text));

        if ($text === '') {
            throw InvalidAnalysisResponse::because('the answer is empty');
        }

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidAnalysisResponse::because('the answer is not valid JSON ('.$exception->getMessage().')');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw InvalidAnalysisResponse::because('the answer must be a JSON object');
        }

        return new AnalysisData(
            rootCause: $this->requiredString($data, 'root_cause'),
            suggestedFix: $this->requiredString($data, 'suggested_fix'),
            affectedFiles: $this->affectedFiles($data),
            severity: $this->severity($data),
            confidence: $this->confidence($data),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidAnalysisResponse::because("\"{$key}\" must be a non-empty string");
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function affectedFiles(array $data): array
    {
        $files = $data['affected_files'] ?? null;

        if (! is_array($files) || ! array_is_list($files)) {
            throw InvalidAnalysisResponse::because('"affected_files" must be an array of strings');
        }

        $result = [];

        foreach ($files as $file) {
            if (! is_string($file)) {
                throw InvalidAnalysisResponse::because('"affected_files" must be an array of strings');
            }

            if (trim($file) !== '') {
                $result[] = trim($file);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function severity(array $data): Severity
    {
        $severity = is_string($data['severity'] ?? null) ? Severity::tryFrom(strtolower($data['severity'])) : null;

        if ($severity === null) {
            throw InvalidAnalysisResponse::because('"severity" must be one of '.implode(', ', Severity::values()));
        }

        return $severity;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function confidence(array $data): float
    {
        $confidence = $data['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw InvalidAnalysisResponse::because('"confidence" must be a number');
        }

        if ($confidence < 0 || $confidence > 1) {
            throw InvalidAnalysisResponse::because('"confidence" must be between 0 and 1');
        }

        return round((float) $confidence, 2);
    }

    /**
     * Providers without structured outputs sometimes wrap JSON in a Markdown fence.
     */
    private function stripCodeFence(string $text): string
    {
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $text, $match) === 1) {
            return trim($match[1]);
        }

        return $text;
    }
}
