<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Fingerprinting;

use MarkusDoell\LogInsight\Contracts\Fingerprinter;
use MarkusDoell\LogInsight\Data\Fingerprint;
use MarkusDoell\LogInsight\Data\LogEntry;
use MarkusDoell\LogInsight\Support\AppPaths;

/**
 * Exceptions: class + normalized message + topmost application frame.
 * Plain log messages: level + normalized message.
 */
final readonly class DefaultFingerprinter implements Fingerprinter
{
    public function __construct(
        private MessageNormalizer $normalizer,
        private AppPaths $appPaths,
    ) {}

    public function fingerprint(LogEntry $entry): Fingerprint
    {
        $message = $this->normalizer->normalize($entry->message);

        if ($entry->exceptionClass === null) {
            return new Fingerprint(
                hash: $this->hash(Fingerprint::TYPE_MESSAGE, $entry->level->value, $message),
                type: Fingerprint::TYPE_MESSAGE,
                normalizedMessage: $message,
                appFrame: null,
            );
        }

        $appFrame = $this->topApplicationFrame($entry);

        return new Fingerprint(
            hash: $this->hash(Fingerprint::TYPE_EXCEPTION, $entry->exceptionClass, $message, $appFrame ?? ''),
            type: Fingerprint::TYPE_EXCEPTION,
            normalizedMessage: $message,
            appFrame: $appFrame,
        );
    }

    /**
     * The throw location counts as the topmost frame; after that the stack
     * trace is searched from the top, skipping vendor and internal frames.
     */
    private function topApplicationFrame(LogEntry $entry): ?string
    {
        $candidates = [[$entry->file, $entry->line]];

        foreach ($entry->frames as $frame) {
            $candidates[] = [$frame->file, $frame->line];
        }

        foreach ($candidates as [$file, $line]) {
            if ($file === null || $line === null) {
                continue;
            }

            $relative = $this->appPaths->relative($file);

            if ($relative !== null) {
                return $relative.':'.$line;
            }
        }

        return null;
    }

    private function hash(string ...$parts): string
    {
        return sha1(implode("\0", $parts));
    }
}
