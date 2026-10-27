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
        private FrameMode $frameMode = FrameMode::Line,
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
     *
     * The function that contains a frame's line is the call shown in the
     * next frame; for the throw location it is the call of frame #0.
     */
    private function topApplicationFrame(LogEntry $entry): ?string
    {
        $calls = [];

        foreach ($entry->frames as $frame) {
            $calls[$frame->index] = $frame->call;
        }

        $candidates = [[$entry->file, $entry->line, $calls[0] ?? null]];

        foreach ($entry->frames as $frame) {
            $candidates[] = [$frame->file, $frame->line, $calls[$frame->index + 1] ?? null];
        }

        foreach ($candidates as [$file, $line, $enclosingCall]) {
            if ($file === null || $line === null) {
                continue;
            }

            $relative = $this->appPaths->relative($file);

            if ($relative === null) {
                continue;
            }

            $function = $this->frameMode === FrameMode::Method && $enclosingCall !== null
                ? $this->functionName($enclosingCall)
                : null;

            return $function === null ? $relative.':'.$line : $relative.'::'.$function;
        }

        return null;
    }

    /**
     * "App\Foo->bar(Object(...), 'x')" becomes "App\Foo::bar". Parts that
     * depend on line numbers (PHP 8.4 closure names, anonymous classes) are
     * reduced to their stable form. Null when the call cannot be read.
     */
    private function functionName(string $call): ?string
    {
        $call = (string) preg_replace('/^\[internal function\]: /', '', $call);

        // "{closure:App\Foo::bar():12}", possibly nested.
        do {
            $call = (string) preg_replace('/\{closure:(?:[^{}]|\{closure\})*\}/', '{closure}', $call, -1, $count);
        } while ($count > 0);

        // "class@anonymous/var/www/html/app/Foo.php:12$0->handle()"
        $call = (string) preg_replace('/class@anonymous\S*?(?=->|::)/', 'class@anonymous', $call);

        if (preg_match('/^(?<function>[^\s()]+)\(/', $call, $match) !== 1) {
            return null;
        }

        $function = str_replace('->', '::', $match['function']);

        return preg_match('/^[\w\\\\{}@\x80-\xff]+(?:::[\w\\\\{}\x80-\xff]+)?$/', $function) === 1 ? $function : null;
    }

    private function hash(string ...$parts): string
    {
        return sha1(implode("\0", $parts));
    }
}
