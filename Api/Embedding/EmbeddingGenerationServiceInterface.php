<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * Store-scoped embedding generation boundary.
 *
 * Implementations must activate and scope to a store view, read store-scoped
 * embedding configuration, resolve exactly one provider (never a fallback),
 * validate inputs and the returned result, and never write anything. Requests
 * to unavailable, misconfigured, or unauthorized providers fail closed with
 * sanitized exceptions.
 */
interface EmbeddingGenerationServiceInterface
{
    /**
     * @param list<string> $texts
     */
    public function embed(int $storeId, EmbeddingInputTypeInterface $inputType, array $texts): EmbeddingResultInterface;
}
