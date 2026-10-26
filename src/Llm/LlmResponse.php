<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Llm;

final readonly class LlmResponse
{
    /**
     * @param  string  $model  the model that actually answered (may differ from the requested one after a fallback)
     * @param  array<array-key, mixed>  $raw  decoded provider response
     */
    public function __construct(
        public string $text,
        public string $model,
        public ?string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public array $raw,
    ) {}

    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
