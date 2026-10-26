<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Support;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Exceptions\InvalidSince;
use Throwable;

/**
 * Turns a --since option ("30m", "24h", "7d", "2w" or a date) into a point in time.
 */
final class Since
{
    public static function parse(string $value, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $value = trim($value);

        if (preg_match('/^(?<amount>\d+)\s*(?<unit>m|min|h|d|w)$/i', $value, $match) === 1) {
            $amount = (int) $match['amount'];

            return match (strtolower($match['unit'])) {
                'm', 'min' => $now->subMinutes($amount),
                'h' => $now->subHours($amount),
                'd' => $now->subDays($amount),
                default => $now->subWeeks($amount),
            };
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw InvalidSince::value($value);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw InvalidSince::value($value);
        }
    }
}
