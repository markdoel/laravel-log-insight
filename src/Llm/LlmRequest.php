<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Llm;

/**
 * Provider-neutral request for a single structured completion.
 */
final readonly class LlmRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON schema the answer must follow
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $schema,
    ) {}

    /**
     * The same conversation with a follow-up exchange appended.
     */
    public function followUp(string $assistantAnswer, string $userMessage): self
    {
        return new self($this->system, [
            ...$this->messages,
            ['role' => 'assistant', 'content' => $assistantAnswer],
            ['role' => 'user', 'content' => $userMessage],
        ], $this->schema);
    }
}
