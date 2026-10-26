<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Clustering;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Data\LogLevel;

final readonly class DetectedOccurrence
{
    /**
     * @param  string  $hash  identifies this occurrence across repeated runs over the same log
     */
    public function __construct(
        public string $hash,
        public CarbonImmutable $occurredAt,
        public LogLevel $level,
        public string $channel,
        public string $message,
    ) {}
}
