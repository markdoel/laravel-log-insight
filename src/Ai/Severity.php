<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    /**
     * This severity and every more severe one.
     *
     * @return list<self>
     */
    public function andAbove(): array
    {
        return array_values(array_filter(self::cases(), fn (self $severity): bool => $severity->rank() >= $this->rank()));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
