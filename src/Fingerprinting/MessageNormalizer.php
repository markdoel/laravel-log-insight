<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Fingerprinting;

/**
 * Replaces the variable parts of an error message (IDs, UUIDs, e-mail
 * addresses, timestamps, paths, ...) with placeholders, so that the same
 * error with different data produces the same text.
 */
final class MessageNormalizer
{
    private const MAX_LENGTH = 1000;

    /**
     * Order matters: specific patterns run before the generic number rule.
     *
     * @var array<string, string>
     */
    private const RULES = [
        // Laravel's QueryException appends connection details and the SQL with its bindings.
        '/\s*\(Connection: [^()]*?SQL: .*\)$/s' => ' (SQL)',
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/' => '{datetime}',
        '/\b\d{4}-\d{2}-\d{2}\b/' => '{date}',
        '/\b\d{2}:\d{2}:\d{2}\b/' => '{time}',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/\b(?=[0-9A-Z]*\d)[0-9A-HJKMNP-TV-Z]{26}\b/' => '{ulid}',
        '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i' => '{email}',
        '/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b/' => '{ip}',
        '#https?://[^\s\'"<>)]+#i' => '{url}',
        '#/tmp/php[A-Za-z0-9]+#' => '/tmp/{tmp}',
        '/\b[0-9a-f]{16,}\b/i' => '{hash}',
        '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}\b/' => '{token}',
        '/\b\d+(?:\.\d+)?\b/' => '{n}',
    ];

    public function normalize(string $message): string
    {
        $message = $this->normalizePaths($message);

        foreach (self::RULES as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($message, 0, self::MAX_LENGTH);
    }

    /**
     * Path segments that mix letters and digits (upload names, cache keys,
     * release directories) are replaced, the path structure is kept.
     */
    private function normalizePaths(string $message): string
    {
        return (string) preg_replace_callback(
            '#(?:/[\w.\-]+){2,}#',
            static fn (array $match): string => (string) preg_replace(
                '#(?<=/)(?=[^/]*\d)(?=[^/]*[A-Za-z])[\w\-]{6,}(?=\.\w+$|/|$)#',
                '{var}',
                $match[0],
            ),
            $message,
        );
    }
}
