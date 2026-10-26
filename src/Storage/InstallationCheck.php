<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Storage;

use MarkusDoell\LogInsight\Exceptions\NotInstalled;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * Turns a forgotten migration into a helpful message instead of an SQL error.
 */
final class InstallationCheck
{
    private bool $verified = false;

    public function ensureInstalled(): void
    {
        if ($this->verified) {
            return;
        }

        $cluster = new Cluster;

        if (! $cluster->getConnection()->getSchemaBuilder()->hasTable($cluster->getTable())) {
            throw NotInstalled::missingTables();
        }

        $this->verified = true;
    }
}
