<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * Sanitized view of one existing assistant-index document.
 *
 * This is not a raw OpenSearch response. It exposes only the fields needed for
 * idempotency and safe vector reuse decisions.
 */
interface IndexedDocumentStateInterface
{
    public function documentId(): string;

    public function completeDocumentHash(): ?string;

    public function embeddingContentHash(): ?string;

    public function embeddingFingerprint(): ?string;

    public function embedding(): mixed;
}
