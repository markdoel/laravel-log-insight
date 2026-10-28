<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Notifications;

use MarkusDoell\LogInsight\Ai\Severity;

/**
 * The content of a severe cluster notification. All text is already redacted,
 * so the queued payload holds no more than what is finally sent.
 */
final readonly class ClusterAlert
{
    /**
     * @param  float  $confidence  between 0 and 1
     */
    public function __construct(
        public int $clusterId,
        public string $error,
        public string $message,
        public ?string $location,
        public int $occurrences,
        public Severity $severity,
        public float $confidence,
        public string $rootCause,
        public string $suggestedFix,
    ) {}

    public function errorName(): string
    {
        return class_basename($this->error);
    }

    public function confidencePercent(): int
    {
        return (int) round($this->confidence * 100);
    }

    public function summary(): string
    {
        return sprintf(
            '%s %s in %s (%d×)',
            ucfirst($this->severity->value),
            $this->errorName(),
            $this->location ?? 'unknown location',
            $this->occurrences,
        );
    }

    public function reportCommand(): string
    {
        return 'php artisan log-insight:report --severity='.$this->severity->value;
    }
}
