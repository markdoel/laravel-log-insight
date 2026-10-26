<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Contracts;

use MarkusDoell\LogInsight\Data\Fingerprint;
use MarkusDoell\LogInsight\Data\LogEntry;

interface Fingerprinter
{
    /**
     * Entries with the same fingerprint describe the same problem and end up
     * in the same cluster.
     */
    public function fingerprint(LogEntry $entry): Fingerprint;
}
