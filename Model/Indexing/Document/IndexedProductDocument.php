<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;

final readonly class IndexedProductDocument implements IndexedProductDocumentInterface
{
    public function __construct(
        private ProductDocumentInterface $document,
        private EmbeddingVectorInterface $embedding,
        private string $embeddingHash,
        private string $embeddingFingerprint,
        private string $indexedAt
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $embeddingHash) !== 1) {
            throw new IndexCompatibilityMismatchException();
        }
        if (preg_match('/^[a-f0-9]{64}$/', $embeddingFingerprint) !== 1) {
            throw new IndexCompatibilityMismatchException();
        }
    }

    public function document(): ProductDocumentInterface
    {
        return $this->document;
    }

    public function embedding(): EmbeddingVectorInterface
    {
        return $this->embedding;
    }

    public function embeddingHash(): string
    {
        return $this->embeddingHash;
    }

    public function embeddingFingerprint(): string
    {
        return $this->embeddingFingerprint;
    }

    public function indexedAt(): string
    {
        return $this->indexedAt;
    }
}
