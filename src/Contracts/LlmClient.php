<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Contracts;

use MarkusDoell\LogInsight\Exceptions\LlmRequestFailed;
use MarkusDoell\LogInsight\Llm\LlmRequest;
use MarkusDoell\LogInsight\Llm\LlmResponse;

/**
 * A large language model provider. Implement this to add providers
 * besides Anthropic.
 */
interface LlmClient
{
    /**
     * Short provider name stored with every analysis, e.g. "anthropic".
     */
    public function provider(): string;

    /**
     * Whether the client is configured well enough to send requests.
     */
    public function isConfigured(): bool;

    /**
     * @throws LlmRequestFailed
     */
    public function complete(LlmRequest $request): LlmResponse;
}
