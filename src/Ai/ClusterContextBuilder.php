<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Support\AppPaths;

/**
 * Builds the user message for a cluster: error summary, representative
 * stack trace and the source code around the affected line. Everything
 * passes through the redactor before it leaves the server.
 */
final readonly class ClusterContextBuilder
{
    private const MAX_SOURCE_FILE_BYTES = 2_000_000;

    public function __construct(
        private Redactor $redactor,
        private AppPaths $appPaths,
        private int $contextLines = 15,
        private int $maxTraceLines = 60,
    ) {}

    public function build(Cluster $cluster): string
    {
        $sections = [$this->error($cluster)];

        if ($cluster->stack_trace !== null && $cluster->stack_trace !== '') {
            $sections[] = "<stack_trace>\n".$this->trace($cluster->stack_trace)."\n</stack_trace>";
        }

        $source = $this->source($cluster);

        if ($source !== null) {
            $sections[] = $source;
        }

        return $this->redactor->redact(implode("\n\n", $sections));
    }

    private function error(Cluster $cluster): string
    {
        $lines = [
            'type: '.$cluster->type,
            'level: '.$cluster->level->value,
        ];

        if ($cluster->exception_class !== null) {
            $lines[] = 'exception_class: '.$cluster->exception_class;
        }

        $lines[] = 'message: '.$cluster->sample_message;

        if ($cluster->file !== null) {
            $lines[] = 'thrown_at: '.($this->appPaths->relative($cluster->file) ?? $cluster->file).':'.$cluster->line;
        }

        if ($cluster->app_frame !== null) {
            $lines[] = 'application_frame: '.$cluster->app_frame;
        }

        $lines[] = sprintf(
            'occurrences: %d between %s and %s',
            $cluster->occurrence_count,
            $cluster->first_seen_at->toDateTimeString(),
            $cluster->last_seen_at->toDateTimeString(),
        );

        return "<error>\n".implode("\n", $lines)."\n</error>";
    }

    private function trace(string $trace): string
    {
        $lines = explode("\n", $trace);

        if (count($lines) <= $this->maxTraceLines) {
            return $trace;
        }

        return implode("\n", array_slice($lines, 0, $this->maxTraceLines))
            ."\n… ".(count($lines) - $this->maxTraceLines).' more lines omitted';
    }

    /**
     * Source excerpt around the topmost application frame, with line numbers
     * and the affected line marked with ">".
     */
    private function source(Cluster $cluster): ?string
    {
        if ($cluster->app_frame === null || preg_match('/^(?<path>.+):(?<line>\d+)$/', $cluster->app_frame, $match) !== 1) {
            return null;
        }

        $file = $this->appPaths->readableLocal($match['path']);

        if ($file === null || filesize($file) > self::MAX_SOURCE_FILE_BYTES) {
            return null;
        }

        $code = file($file, FILE_IGNORE_NEW_LINES);
        $line = (int) $match['line'];

        if ($code === false || $line < 1 || $line > count($code)) {
            return null;
        }

        $from = max(1, $line - $this->contextLines);
        $to = min(count($code), $line + $this->contextLines);
        $width = strlen((string) $to);
        $excerpt = [];

        for ($number = $from; $number <= $to; $number++) {
            $excerpt[] = sprintf('%s %s | %s', $number === $line ? '>' : ' ', str_pad((string) $number, $width, ' ', STR_PAD_LEFT), $code[$number - 1]);
        }

        return sprintf("<source file=\"%s\" line=\"%d\">\n%s\n</source>", $match['path'], $line, implode("\n", $excerpt));
    }
}
