<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class NotInstalled extends LogInsightException
{
    public static function missingTables(): self
    {
        return new self('The log_insight_* tables do not exist. Run: php artisan vendor:publish --tag=log-insight-migrations && php artisan migrate');
    }

    public static function missingEmbeddingsTable(): self
    {
        return new self('Similar clusters need the log_insight_cluster_embeddings table. Run: php artisan vendor:publish --tag=log-insight-similarity-migrations && php artisan migrate');
    }
}
