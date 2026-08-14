<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * The type of the text being embedded.
 *
 * Providers such as Voyage require an explicit document/query distinction so
 * that retrieval vectors are comparable to query vectors. The value is a
 * provider-neutral wire value, never a free-form string.
 */
interface EmbeddingInputTypeInterface
{
    public const DOCUMENT = 'document';
    public const QUERY = 'query';

    public function value(): string;

    public function isDocument(): bool;

    public function isQuery(): bool;
}
