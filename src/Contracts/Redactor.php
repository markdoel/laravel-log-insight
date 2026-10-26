<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Contracts;

interface Redactor
{
    /**
     * Remove secrets and personal data from text before it leaves the server.
     */
    public function redact(string $text): string;
}
