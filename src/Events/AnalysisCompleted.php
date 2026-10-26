<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Events;

use Illuminate\Queue\SerializesModels;
use MarkusDoell\LogInsight\Models\Analysis;

/**
 * Fired after the AI provider's analysis of a cluster has been stored.
 */
final class AnalysisCompleted
{
    use SerializesModels;

    public function __construct(
        public readonly Analysis $analysis,
    ) {}
}
