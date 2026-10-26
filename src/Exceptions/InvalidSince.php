<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class InvalidSince extends LogInsightException
{
    public static function value(string $value): self
    {
        return new self("Invalid --since value [{$value}]. Use a duration like 30m, 24h, 7d, 2w or a date like 2026-01-31.");
    }
}
