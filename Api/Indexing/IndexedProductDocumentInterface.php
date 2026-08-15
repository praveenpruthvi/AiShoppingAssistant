<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Immutable product document enriched with its embedding vector for one run.
 *
 * The embedding hash fingerprints the vector values so unchanged embeddings can
 * be detected without re-embedding, and the fingerprint ties the vector to the
 * store's embedding configuration that produced it. Never carries provider
 * configuration or secrets.
 */
interface IndexedProductDocumentInterface
{
    /**
     * @throws ProductIndexingException when the vector is incompatible
     */
    public function document(): ProductDocumentInterface;

    /**
     * @throws ProductIndexingException when the vector is incompatible
     */
    public function embedding(): EmbeddingVectorInterface;

    /**
     * SHA-256 of the vector values.
     *
     * @throws ProductIndexingException when the vector is incompatible
     */
    public function embeddingHash(): string;

    /**
     * Content-hash fingerprint of the embedding configuration used to generate
     * the vector.
     *
     * @throws ProductIndexingException when the vector is incompatible
     */
    public function embeddingFingerprint(): string;

    /**
     * Timestamp when the document was indexed into the run, ISO-8601 UTC.
     *
     * @throws ProductIndexingException when the vector is incompatible
     */
    public function indexedAt(): string;
}
