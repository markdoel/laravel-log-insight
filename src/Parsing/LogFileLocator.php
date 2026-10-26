<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Parsing;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Exceptions\LogFileNotFound;

/**
 * Finds the log files to analyze: either an explicit file, or every file in
 * the log directory that matches the configured pattern ("single" and
 * "daily" channels).
 */
final readonly class LogFileLocator
{
    public function __construct(
        private string $directory,
        private string $pattern = 'laravel*.log',
    ) {}

    /**
     * @return list<string>
     */
    public function locate(?string $file = null, ?CarbonImmutable $since = null): array
    {
        if ($file !== null) {
            if (! is_file($file) || ! is_readable($file)) {
                throw LogFileNotFound::at($file);
            }

            return [$file];
        }

        $files = glob(rtrim($this->directory, '/').'/'.$this->pattern) ?: [];
        $files = array_values(array_filter($files, is_file(...)));

        if ($since !== null) {
            $files = array_values(array_filter($files, fn (string $path): bool => ! $this->isDailyFileBefore($path, $since)));
        }

        sort($files);

        return $files;
    }

    /**
     * Daily files ("laravel-2026-01-31.log") older than the cut-off day can
     * be skipped without reading them.
     */
    private function isDailyFileBefore(string $path, CarbonImmutable $since): bool
    {
        if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', basename($path), $match) !== 1) {
            return false;
        }

        return $match[1] < $since->toDateString();
    }
}
