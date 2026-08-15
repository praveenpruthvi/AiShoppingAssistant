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
 * correlated. The frozen embedding configuration comes from the calling writer's
 * run state and is re-validated before and after every provider request; a
 * mid-run configuration change fails the run. The service never reads or
 * exposes provider secrets.
 */
interface EmbeddingEnrichmentServiceInterface
{
    /**
     * @param list<ProductDocumentInterface> $documents
     *
     * @return list<IndexedProductDocumentInterface> same order as $documents
     *
     * @throws ProductIndexingException when embeddings cannot be generated or
     *     correlated, or when the frozen configuration no longer matches the
     *     live configuration
     */
    public function enrich(FrozenEmbeddingConfigInterface $config, array $documents): array;
}