<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

/**
 * A validated answer from the AI provider.
 */
final readonly class AnalysisData
{
    /**
     * @param  list<string>  $affectedFiles
     * @param  float  $confidence  between 0 and 1
     */
    public function __construct(
        public string $rootCause,
        public string $suggestedFix,
        public array $affectedFiles,
        public Severity $severity,
        public float $confidence,
    ) {}
}
