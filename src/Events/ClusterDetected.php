<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Events;

use Illuminate\Queue\SerializesModels;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * Fired when a fingerprint is seen for the first time.
 */
final class ClusterDetected
{
    use SerializesModels;

    public function __construct(
        public readonly Cluster $cluster,
    ) {}
}
