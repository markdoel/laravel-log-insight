<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Storage;

use MarkusDoell\LogInsight\Models\Cluster;

final readonly class StoredCluster
{
    public function __construct(
        public Cluster $cluster,
        public bool $isNew,
        public int $newOccurrences,
    ) {}
}
