<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Contracts;

use MarkusDoell\LogInsight\Exceptions\EmbeddingRequestFailed;

/**
 * An embedding model provider. Implement this to add providers besides
 * Voyage AI.
 */
interface EmbeddingClient
{
    /**
     * Short provider name, e.g. "voyage".
     */
    public function provider(): string;

    /**
     * The model stored with every embedding. Only vectors of the same model
     * are compared with each other.
     */
    public function model(): string;

    /**
     * Whether the client is configured well enough to send requests.
     */
    public function isConfigured(): bool;

    /**
     * One vector per text, in the order of the texts. The texts must be
     * redacted already.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws EmbeddingRequestFailed
     */
    public function embed(array $texts): array;
}
