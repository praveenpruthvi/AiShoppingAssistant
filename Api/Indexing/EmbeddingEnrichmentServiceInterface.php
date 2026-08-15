<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Enriches normalized product documents with embeddings for the current store.
 *
 * Embedding generation is bounded: texts are chunked into provider-sized
 * requests, and the whole batch fails closed when any document cannot be
 * correlated. The store id and embedding fingerprint come from the calling
 * writer's run state; the service never reads or exposes provider secrets.
 */
interface EmbeddingEnrichmentServiceInterface
{
    /**
     * @param list<ProductDocumentInterface> $documents
     *
     * @return list<IndexedProductDocumentInterface> same order as $documents
     *
     * @throws ProductIndexingException when embeddings cannot be generated or correlated
     */
    public function enrich(int $storeId, string $embeddingFingerprint, array $documents): array;
}