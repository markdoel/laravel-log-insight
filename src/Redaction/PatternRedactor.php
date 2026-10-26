<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Redaction;

use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Exceptions\InvalidRedactionPattern;

/**
 * Regex based redaction of secrets, tokens, passwords, e-mail and IP
 * addresses, extendable with custom patterns.
 */
final readonly class PatternRedactor implements Redactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * Keys whose values are always treated as secret.
     */
    private const SECRET_KEYS = 'passw(?:or)?d|pwd|pass|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret'
        .'|auth(?:orization)?|auth[_-]?token|credentials?|cookie|session[_-]?id|signature|salt|otp|pin';

    /**
     * Rules applied in order. Specific formats come before generic ones.
     *
     * @var array<string, string>
     */
    private const RULES = [
        // PEM private keys
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[REDACTED_PRIVATE_KEY]',

        // JSON Web Tokens
        '/\beyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => '[REDACTED_JWT]',

        // Authorization headers ("Authorization: Basic abc") and bearer tokens
        '/\b((?:Proxy-)?Authorization\s*[:=]\s*(?:(?:Bearer|Basic|Digest|Token)\s+)?)(?!(?:Bearer|Basic|Digest|Token)\s)[^\s\'",;\[]+/i' => '$1'.self::REDACTED,
        '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/' => '$1 '.self::REDACTED,

        // Well-known token formats
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => '[REDACTED_TOKEN]',
        '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/' => '[REDACTED_TOKEN]',
        '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/' => '[REDACTED_TOKEN]',
        '/\bxox[abposr]-[A-Za-z0-9\-]{10,}/' => '[REDACTED_TOKEN]',
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}\b/' => '[REDACTED_TOKEN]',
        '/\bsk-ant-[A-Za-z0-9_\-]{20,}/' => '[REDACTED_TOKEN]',
        '/\bsk-(?:proj-)?[A-Za-z0-9_\-]{20,}/' => '[REDACTED_TOKEN]',
        '/\bAIza[0-9A-Za-z_\-]{35}/' => '[REDACTED_TOKEN]',
        '#\bbase64:[A-Za-z0-9+/]{40,}={0,2}#' => '[REDACTED_TOKEN]',

        // Credentials in connection strings: scheme://user:password@host
        '#\b([a-z][a-z0-9+.\-]*://)[^\s:/@]+:[^\s/@]+@#i' => '$1'.self::REDACTED.'@',
    ];

    /** @var array<string, string> */
    private array $customPatterns;

    /**
     * @param  array<string, string>  $customPatterns  regex => replacement
     */
    public function __construct(
        array $customPatterns = [],
        private bool $redactTraceArguments = true,
    ) {
        foreach (array_keys($customPatterns) as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw InvalidRedactionPattern::pattern($pattern);
            }
        }

        $this->customPatterns = $customPatterns;
    }

    public function redact(string $text): string
    {
        foreach (self::RULES as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        $text = $this->redactKeyValuePairs($text);
        $text = $this->redactEmails($text);
        $text = $this->redactIpAddresses($text);

        if ($this->redactTraceArguments) {
            $text = $this->redactTraceArguments($text);
        }

        foreach ($this->customPatterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * password=..., "api_key":"...", 'secret' => '...', token: ...
     * Keys and quotes are kept, only the value is replaced.
     */
    private function redactKeyValuePairs(string $text): string
    {
        $pattern = '/(?<key>(?:\\\\?["\'`])?\b[\w\-]*(?:'.self::SECRET_KEYS.')\b(?:\\\\?["\'`])?)'
            .'(?<separator>\s*(?:=>|=(?!=)|:(?!:))\s*)'
            .'(?<value>\\\\"(?:[^"\\\\]|\\\\[^"])*\\\\"|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s,;&}\])\'"]+)/i';

        return (string) preg_replace_callback($pattern, static function (array $match): string {
            $value = $match['value'];

            // Already handled by a more specific rule, e.g. "Authorization: Bearer [REDACTED]".
            if (str_starts_with($value, '[REDACTED') || preg_match('/^(?:Bearer|Basic|Digest|Token)$/i', $value) === 1) {
                return $match[0];
            }

            $quote = match (true) {
                str_starts_with($value, '\\"') => '\\"',
                str_starts_with($value, '"') => '"',
                str_starts_with($value, "'") => "'",
                default => '',
            };

            return $match['key'].$match['separator'].$quote.self::REDACTED.$quote;
        }, $text);
    }

    private function redactEmails(string $text): string
    {
        return (string) preg_replace('/[a-z0-9._%+\-]+@[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,}/i', '[REDACTED_EMAIL]', $text);
    }

    private function redactIpAddresses(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.]*\d)/',
            static fn (array $match): string => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? '[REDACTED_IP]' : $match[0],
            $text,
        );

        // IPv6 candidates are validated, so PHP's "Class::method" never matches.
        return (string) preg_replace_callback(
            '/(?<![\w:\\\\])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i',
            static fn (array $match): string => preg_match('/\d/', $match[0]) === 1
                && filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[REDACTED_IP]' : $match[0],
            $text,
        );
    }

    /**
     * String arguments in stack frames ("#3 /app/X.php(12): login('jane', 'hunter2')")
     * may contain anything, including passwords.
     */
    private function redactTraceArguments(string $text): string
    {
        return (string) preg_replace_callback(
            '/^(\s*#\d+ [^\n]*?: )([^\n]*)$/m',
            static fn (array $match): string => $match[1].(string) preg_replace(
                "/'(?:[^'\\\\]|\\\\.)*'/",
                "'".self::REDACTED."'",
                $match[2],
            ),
            $text,
        );
    }
}
