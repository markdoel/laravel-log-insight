<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class InvalidAnalysisResponse extends LogInsightException
{
    public static function because(string $reason): self
    {
        return new self("Invalid analysis response: {$reason}");
    }
}
