<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class NotInstalled extends LogInsightException
{
    public static function missingTables(): self
    {
        return new self('The log_insight_* tables do not exist. Run: php artisan vendor:publish --tag=log-insight-migrations && php artisan migrate');
    }
}
