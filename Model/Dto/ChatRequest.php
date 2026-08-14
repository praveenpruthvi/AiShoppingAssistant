<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ChatRequest
{
    /**
     * @param non-empty-list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?array $responseSchema = null,
        public int $maxOutputTokens = 1200
    ) {
        if ($messages === []) {
            throw new InvalidArgumentException('A chat request requires at least one message.');
        }

        foreach ($messages as $message) {
            if (!$message instanceof ChatMessage) {
                throw new InvalidArgumentException('Every chat request message must be a ChatMessage.');
            }
        }

        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('Maximum output tokens must be greater than zero.');
        }
    }
}
