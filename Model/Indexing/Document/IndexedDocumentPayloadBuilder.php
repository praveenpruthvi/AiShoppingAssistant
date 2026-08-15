<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;

/**
 * Converts an indexed product document into the flat storage payload persisted
 * to the assistant index.
 *
 * Field names come from ProductIndexMappingInterface so the payload always
 * matches the mapping. The embedded _id carries the stable document id used by
 * the write client for upserts.
 */
final class IndexedDocumentPayloadBuilder
{
    /**
     * @return array<string, mixed> payload including the _id key
     */
    public function build(IndexedProductDocumentInterface $indexed): array
    {
        $document = $indexed->document();

        $categories = [];
        foreach ($document->categories() as $category) {
            $categories[] = [
                ProductIndexMappingInterface::FIELD_CATEGORY_ID => $category->categoryId(),
                ProductIndexMappingInterface::FIELD_CATEGORY_NAME => $category->name(),
                ProductIndexMappingInterface::FIELD_CATEGORY_PATH => $category->path(),
            ];
        }

        $attributes = [];
        foreach ($document->attributes() as $attribute) {
            $attributes[] = [
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_CODE => $attribute->code(),
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_LABEL => $attribute->label(),
                ProductIndexMappingInterface::FIELD_ATTRIBUTE_VALUES => $attribute->values(),
            ];
        }

        $payload = [
            '_id' => $document->documentId(),
            ProductIndexMappingInterface::FIELD_DOCUMENT_ID => $document->documentId(),
            ProductIndexMappingInterface::FIELD_ENTITY_ID => $document->entityId(),
            ProductIndexMappingInterface::FIELD_SKU => $document->sku(),
            ProductIndexMappingInterface::FIELD_STORE_ID => (string)$document->storeId(),
            ProductIndexMappingInterface::FIELD_WEBSITE_IDS => array_map('strval', $document->websiteIds()),
            ProductIndexMappingInterface::FIELD_PRODUCT_TYPE => $document->productType(),
            ProductIndexMappingInterface::FIELD_NAME => $document->name(),
            ProductIndexMappingInterface::FIELD_SHORT_DESCRIPTION => $document->shortDescription(),
            ProductIndexMappingInterface::FIELD_LONG_DESCRIPTION => $document->longDescription(),
            ProductIndexMappingInterface::FIELD_IS_ENABLED => $document->isEnabled(),
            ProductIndexMappingInterface::FIELD_VISIBILITY => $document->visibility(),
            ProductIndexMappingInterface::FIELD_CATEGORIES => $categories,
            ProductIndexMappingInterface::FIELD_ATTRIBUTES => $attributes,
            ProductIndexMappingInterface::FIELD_SEARCHABLE_TEXT => $document->searchableText(),
            ProductIndexMappingInterface::FIELD_EMBEDDING_CONTENT_HASH => $document->embeddingContentHash(),
            ProductIndexMappingInterface::FIELD_COMPLETE_DOCUMENT_HASH => $document->completeDocumentHash(),
            ProductIndexMappingInterface::FIELD_EMBEDDING => $indexed->embedding()->values(),
            ProductIndexMappingInterface::FIELD_EMBEDDING_HASH => $indexed->embeddingHash(),
            ProductIndexMappingInterface::FIELD_EMBEDDING_FINGERPRINT => $indexed->embeddingFingerprint(),
            ProductIndexMappingInterface::FIELD_INDEXED_AT => $indexed->indexedAt(),
        ];

        if ($document->updatedAt() !== null) {
            $payload[ProductIndexMappingInterface::FIELD_UPDATED_AT] = $document->updatedAt();
        }

        $this->assertPayload($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPayload(array $payload): void
    {
        $embedding = $payload[ProductIndexMappingInterface::FIELD_EMBEDDING];
        if (!is_array($embedding) || $embedding === []) {
            throw new IndexCompatibilityMismatchException();
        }
        foreach ($embedding as $value) {
            if (!is_float($value) && !is_int($value)) {
                throw new IndexCompatibilityMismatchException();
            }
        }
    }
}