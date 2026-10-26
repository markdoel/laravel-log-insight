<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class InvalidRedactionPattern extends LogInsightException
{
    public static function pattern(string $pattern): self
    {
        return new self("Invalid redaction pattern [{$pattern}] in config log-insight.redaction.patterns.");
    }
}
