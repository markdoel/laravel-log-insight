<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
