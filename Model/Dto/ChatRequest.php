<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use InvalidArgumentException;

/**
 * A fully validated, store-scoped chat request ready to send to an LLM provider.
 *
 * Mirrors EmbeddingRequest: the request carries the store context and the
 * resolved config snapshot (model, base URL, API key, timeout) for that store
 * alongside the conversation payload, so provider adapters stay stateless and
 * never retain configuration or secrets between calls.
 */
final readonly class ChatRequest
{
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 300;
    public const MIN_MAX_OUTPUT_TOKENS = 1;
    public const MAX_MAX_OUTPUT_TOKENS = 8192;

    /**
     * @param non-empty-list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    public function __construct(
        public int $storeId,
        public array $messages,
        public string $model,
        public string $baseUrl,
        public SecretValue $apiKey,
        public int $timeoutSeconds,
        public array $tools = [],
        public ?array $responseSchema = null,
        public int $maxOutputTokens = 1200
    ) {
        if ($storeId < 1) {
            throw new InvalidArgumentException('A chat request requires an active store id.');
        }

        if ($messages === []) {
            throw new InvalidArgumentException('A chat request requires at least one message.');
        }

        foreach ($messages as $message) {
            if (!$message instanceof ChatMessage) {
                throw new InvalidArgumentException('Every chat request message must be a ChatMessage.');
            }
        }

        if ($model === '') {
            throw new InvalidArgumentException('Chat request model must not be empty.');
        }

        if ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('Chat request timeout is outside the supported range.');
        }

        if ($maxOutputTokens < self::MIN_MAX_OUTPUT_TOKENS || $maxOutputTokens > self::MAX_MAX_OUTPUT_TOKENS) {
            throw new InvalidArgumentException('Maximum output tokens is outside the supported range.');
        }
    }
}
