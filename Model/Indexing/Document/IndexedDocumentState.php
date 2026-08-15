<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedDocumentStateInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexDocumentStateInvalidException;

/**
 * Sanitized existing document state used by incremental indexing.
 */
final class IndexedDocumentState implements IndexedDocumentStateInterface
{
    public function __construct(
        private readonly string $documentId,
        private readonly ?string $completeDocumentHash,
        private readonly ?string $embeddingContentHash,
        private readonly ?string $embeddingFingerprint,
        private readonly mixed $embedding
    ) {
        if ($documentId === '') {
            throw new IndexDocumentStateInvalidException();
        }

        foreach ([$completeDocumentHash, $embeddingContentHash, $embeddingFingerprint] as $hash) {
            if ($hash !== null && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new IndexDocumentStateInvalidException();
            }
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromSource(string $expectedDocumentId, array $source): self
    {
        $documentId = $source[ProductIndexMappingInterface::FIELD_DOCUMENT_ID] ?? null;
        if (!is_string($documentId) || $documentId !== $expectedDocumentId) {
            throw new IndexDocumentStateInvalidException();
        }

        return new self(
            $documentId,
            self::nullableString($source[ProductIndexMappingInterface::FIELD_COMPLETE_DOCUMENT_HASH] ?? null),
            self::nullableString($source[ProductIndexMappingInterface::FIELD_EMBEDDING_CONTENT_HASH] ?? null),
            self::nullableString($source[ProductIndexMappingInterface::FIELD_EMBEDDING_FINGERPRINT] ?? null),
            $source[ProductIndexMappingInterface::FIELD_EMBEDDING] ?? null
        );
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function completeDocumentHash(): ?string
    {
        return $this->completeDocumentHash;
    }

    public function embeddingContentHash(): ?string
    {
        return $this->embeddingContentHash;
    }

    public function embeddingFingerprint(): ?string
    {
        return $this->embeddingFingerprint;
    }

    public function embedding(): mixed
    {
        return $this->embedding;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
