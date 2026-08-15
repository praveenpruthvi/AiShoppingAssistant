<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Builds the validated create-body (settings + mapping) for one store's
 * physical assistant index.
 *
 * The mapping is store-scoped: the vector field dimension is fixed from the
 * store's embedding configuration so the same mapping stays compatible for the
 * whole run. It carries only non-secret metadata in _meta; fingerprint hashes
 * never include raw API keys or model credentials.
 */
interface ProductIndexMappingInterface
{
    /**
     * Version of the assistant mapping structure. Increment when field layout
     * or settings change incompatibly.
     */
    public const MAPPING_VERSION = 2;

    /**
     * Bounded, non-disabled refresh interval applied at index creation so
     * incremental documents eventually become searchable without refreshing
     * each document. The writer still refreshes explicitly before alias
     * activation.
     */
    public const REFRESH_INTERVAL = '30s';

    /**
     * OpenSearch k-NN space type for semantic embeddings (cosine similarity).
     * Mirrored inside the knn_vector method block.
     */
    public const KNN_SPACE_TYPE = 'cosinesimil';

    /** Stable physical index field names. Shared with the document payload builder. */
    public const FIELD_DOCUMENT_ID = 'document_id';
    public const FIELD_ENTITY_ID = 'entity_id';
    public const FIELD_SKU = 'sku';
    public const FIELD_STORE_ID = 'store_id';
    public const FIELD_WEBSITE_IDS = 'website_ids';
    public const FIELD_PRODUCT_TYPE = 'product_type';
    public const FIELD_NAME = 'name';
    public const FIELD_SHORT_DESCRIPTION = 'short_description';
    public const FIELD_LONG_DESCRIPTION = 'long_description';
    public const FIELD_IS_ENABLED = 'is_enabled';
    public const FIELD_VISIBILITY = 'visibility';
    public const FIELD_CATEGORIES = 'categories';
    public const FIELD_ATTRIBUTES = 'attributes';
    public const FIELD_SEARCHABLE_TEXT = 'searchable_text';
    public const FIELD_EMBEDDING_CONTENT_HASH = 'embedding_content_hash';
    public const FIELD_COMPLETE_DOCUMENT_HASH = 'complete_document_hash';
    public const FIELD_UPDATED_AT = 'updated_at';
    public const FIELD_EMBEDDING = 'embedding';
    public const FIELD_EMBEDDING_HASH = 'embedding_hash';
    public const FIELD_EMBEDDING_FINGERPRINT = 'embedding_fingerprint';
    public const FIELD_INDEXED_AT = 'indexed_at';

    /** Nested category field names. */
    public const FIELD_CATEGORY_ID = 'category_id';
    public const FIELD_CATEGORY_NAME = 'name';
    public const FIELD_CATEGORY_PATH = 'path';

    /** Nested attribute field names. */
    public const FIELD_ATTRIBUTE_CODE = 'code';
    public const FIELD_ATTRIBUTE_LABEL = 'label';
    public const FIELD_ATTRIBUTE_VALUES = 'values';

    /**
     * @param StoreScopeInterface $scope store scope this physical index serves
     * @param RebuildRunContextInterface $context run the index belongs to
     * @param int $embeddingDimensions vector dimension from the store embedding config
     * @param string $embeddingFingerprint content-hash fingerprint of the store embedding config
     * @param string $embeddingBaseUrlHash content-hash of the embedding base URL (never the URL itself)
     * @param string $physicalIndexName validated name of the physical index
     *
     * @return array<string, mixed> create body with "settings" and "mappings"
     *
     * @throws ProductIndexingException when inputs are invalid
     */
    public function createBody(
        StoreScopeInterface $scope,
        RebuildRunContextInterface $context,
        int $embeddingDimensions,
        string $embeddingFingerprint,
        string $embeddingBaseUrlHash,
        string $physicalIndexName
    ): array;
}
