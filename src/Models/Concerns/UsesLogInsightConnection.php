<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Models\Concerns;

trait UsesLogInsightConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('log-insight.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }
}
