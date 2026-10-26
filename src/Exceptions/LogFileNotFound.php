<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Exceptions;

final class LogFileNotFound extends LogInsightException
{
    public static function at(string $path): self
    {
        return new self("Log file [{$path}] does not exist or is not readable.");
    }
}
