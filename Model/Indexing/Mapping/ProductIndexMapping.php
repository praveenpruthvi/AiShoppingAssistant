<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Mapping;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Validated OpenSearch create-body builder for one store's assistant index.
 *
 * dynamic is disabled so catalogue content can never introduce unexpected
 * fields. _meta records non-secret provenance for diagnostics and
 * compatibility checks. The vector field dimension is store-specific and
 * frozen per run; it never reflects a live provider query.
 */
final class ProductIndexMapping implements ProductIndexMappingInterface
{
    public function createBody(
        StoreScopeInterface $scope,
        RebuildRunContextInterface $context,
        int $embeddingDimensions,
        string $embeddingFingerprint,
        string $embeddingBaseUrlHash,
        string $physicalIndexName
    ): array {
        $this->assertDimensions($embeddingDimensions);
        $this->assertHex($embeddingFingerprint, 'fingerprint');
        $this->assertHex($embeddingBaseUrlHash, 'base-url hash');

        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 1,
                'index' => [
                    'refresh_interval' => '-1',
                ],
            ],
            'mappings' => [
                'dynamic' => false,
                '_meta' => [
                    'assistant_index' => true,
                    'schema_version' => $context->schemaVersion(),
                    'mapping_version' => self::MAPPING_VERSION,
                    'store_id' => $scope->storeId(),
                    'website_id' => $scope->websiteId(),
                    'run_id' => $context->runId(),
                    'physical_index' => $physicalIndexName,
                    'embedding_fingerprint' => $embeddingFingerprint,
                    'embedding_dimensions' => $embeddingDimensions,
                    'embedding_base_url_hash' => $embeddingBaseUrlHash,
                ],
                'properties' => [
                    self::FIELD_DOCUMENT_ID => ['type' => 'keyword'],
                    self::FIELD_ENTITY_ID => ['type' => 'long'],
                    self::FIELD_SKU => ['type' => 'keyword'],
                    self::FIELD_STORE_ID => ['type' => 'keyword'],
                    self::FIELD_WEBSITE_IDS => ['type' => 'keyword'],
                    self::FIELD_PRODUCT_TYPE => ['type' => 'keyword'],
                    self::FIELD_NAME => [
                        'type' => 'text',
                        'fields' => [
                            'keyword' => ['type' => 'keyword', 'ignore_above' => 512],
                        ],
                    ],
                    self::FIELD_SHORT_DESCRIPTION => ['type' => 'text'],
                    self::FIELD_LONG_DESCRIPTION => ['type' => 'text'],
                    self::FIELD_IS_ENABLED => ['type' => 'boolean'],
                    self::FIELD_VISIBILITY => ['type' => 'integer'],
                    self::FIELD_CATEGORIES => [
                        'type' => 'nested',
                        'properties' => [
                            self::FIELD_CATEGORY_ID => ['type' => 'long'],
                            self::FIELD_CATEGORY_NAME => ['type' => 'keyword'],
                            self::FIELD_CATEGORY_PATH => ['type' => 'keyword'],
                        ],
                    ],
                    self::FIELD_ATTRIBUTES => [
                        'type' => 'nested',
                        'properties' => [
                            self::FIELD_ATTRIBUTE_CODE => ['type' => 'keyword'],
                            self::FIELD_ATTRIBUTE_LABEL => ['type' => 'keyword'],
                            self::FIELD_ATTRIBUTE_VALUES => ['type' => 'keyword'],
                        ],
                    ],
                    self::FIELD_SEARCHABLE_TEXT => ['type' => 'text'],
                    self::FIELD_EMBEDDING_CONTENT_HASH => ['type' => 'keyword'],
                    self::FIELD_COMPLETE_DOCUMENT_HASH => ['type' => 'keyword'],
                    self::FIELD_UPDATED_AT => ['type' => 'date'],
                    self::FIELD_EMBEDDING => [
                        'type' => 'knn_vector',
                        'dimension' => $embeddingDimensions,
                    ],
                    self::FIELD_EMBEDDING_HASH => ['type' => 'keyword'],
                    self::FIELD_EMBEDDING_FINGERPRINT => ['type' => 'keyword'],
                    self::FIELD_INDEXED_AT => ['type' => 'date'],
                ],
            ],
        ];
    }

    private function assertDimensions(int $dimensions): void
    {
        if ($dimensions < 1 || $dimensions > 65536) {
            throw new IndexCompatibilityMismatchException();
        }
    }

    private function assertHex(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new ProductIndexNameInvalidException();
        }
    }
}