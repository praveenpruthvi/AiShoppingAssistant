<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingEnrichmentServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;

/**
 * Bounded, store-scoped embedding enrichment for indexed product documents.
 *
 * Documents are chunked into provider-sized batches (max 50 per request) and
 * always embedded as document-type inputs so retrieval vectors are comparable
 * to query vectors. Every response vector is correlated to its input by
 * position; any mismatch fails the whole batch. The frozen embedding config is
 * re-validated before and after each provider request so a mid-run
 * configuration change fails the run instead of silently producing an
 * incompatible index. The vector hash and the embedding fingerprint are
 * computed here so the writer stores stable fingerprints, never raw vector or
 * provider data.
 */
final class EmbeddingEnrichmentService implements EmbeddingEnrichmentServiceInterface
{
    /** Maximum documents embedded in one provider request. */
    public const MAX_DOCUMENTS_PER_BATCH = 50;

    public function __construct(
        private readonly EmbeddingGenerationServiceInterface $embeddingGeneration,
        private readonly ContentHashServiceInterface $contentHashService,
        private readonly EmbeddingConfigSnapshotServiceInterface $configSnapshot
    ) {
    }

    public function enrich(FrozenEmbeddingConfigInterface $config, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $indexed = [];

        foreach ($this->chunks($documents) as $chunk) {
            $indexed = array_merge($indexed, $this->enrichChunk($config, $chunk));
        }

        return $indexed;
    }

    /**
     * @param list<ProductDocumentInterface> $chunk
     *
     * @return list<IndexedProductDocumentInterface>
     */
    private function enrichChunk(FrozenEmbeddingConfigInterface $config, array $chunk): array
    {
        $this->assertConfigStable($config);

        $texts = array_map(
            static fn (ProductDocumentInterface $document): string => $document->searchableText(),
            $chunk
        );

        try {
            $result = $this->embeddingGeneration->embed(
                $config->storeId(),
                EmbeddingInputType::document(),
                $texts
            );
        } catch (\Throwable $throwable) {
            throw new EmbeddingEnrichmentException();
        }

        $this->assertConfigStable($config);

        $vectors = $result->vectors();

        if (count($vectors) !== count($chunk)) {
            throw new EmbeddingEnrichmentException();
        }

        $indexed = [];
        $indexedAt = gmdate('c');

        foreach ($chunk as $position => $document) {
            $vector = $vectors[$position];

            if ($vector->dimension() < 1) {
                throw new EmbeddingEnrichmentException();
            }

            try {
                $embeddingHash = $this->contentHashService->hash($vector->values());
            } catch (\Throwable $throwable) {
                throw new EmbeddingEnrichmentException();
            }

            $indexed[] = new IndexedProductDocument(
                $document,
                $vector,
                $embeddingHash,
                $config->fingerprint(),
                $indexedAt
            );
        }

        return $indexed;
    }

    private function assertConfigStable(FrozenEmbeddingConfigInterface $config): void
    {
        if (!$this->configSnapshot->matches($config)) {
            throw new IndexCompatibilityMismatchException();
        }
    }

    /**
     * @param list<ProductDocumentInterface> $documents
     *
     * @return list<list<ProductDocumentInterface>>
     */
    private function chunks(array $documents): array
    {
        return array_chunk($documents, self::MAX_DOCUMENTS_PER_BATCH);
    }
}