<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface as Field;

/**
 * Builds the two Phase-1 retrieval query bodies against the assistant index
 * mapping (ProductIndexMappingInterface).
 *
 * Both queries filter on store_id and is_enabled even though the caller
 * already queries a store-scoped alias and the eligibility policy already
 * gates enabled/search-visible products at index time — belt-and-suspenders,
 * matching this codebase's existing redundant-validation convention (e.g.
 * AbstractEmbeddingProvider re-checking request fields its DTO already
 * guarantees), and a real defense against briefly stale async-indexed data.
 */
final class SearchQueryBuilder
{
    /**
     * @var list<string>
     */
    private const SOURCE_FIELDS = [
        Field::FIELD_ENTITY_ID,
        Field::FIELD_SKU,
        Field::FIELD_STORE_ID,
        Field::FIELD_NAME,
        Field::FIELD_SHORT_DESCRIPTION,
        Field::FIELD_CATEGORIES,
        Field::FIELD_ATTRIBUTES,
        Field::FIELD_IS_ENABLED,
        Field::FIELD_VISIBILITY,
        Field::FIELD_RATING_AVERAGE,
        Field::FIELD_REVIEW_COUNT,
        Field::FIELD_CATALOG_RATING_AVERAGE,
    ];

    /**
     * BM25 keyword query across name, descriptions, searchable text, and the
     * nested categories/attributes.
     *
     * @return array<string, mixed>
     */
    public function keyword(int $storeId, string $queryText, int $size): array
    {
        return [
            'size' => $size,
            '_source' => self::SOURCE_FIELDS,
            'query' => [
                'bool' => [
                    'filter' => $this->scopeFilters($storeId),
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $queryText,
                                'fields' => [
                                    Field::FIELD_NAME . '^3',
                                    Field::FIELD_SHORT_DESCRIPTION,
                                    Field::FIELD_LONG_DESCRIPTION,
                                    Field::FIELD_SEARCHABLE_TEXT . '^2',
                                ],
                            ],
                        ],
                        [
                            'nested' => [
                                'path' => Field::FIELD_CATEGORIES,
                                'query' => [
                                    'match' => [
                                        Field::FIELD_CATEGORIES . '.' . Field::FIELD_CATEGORY_NAME => $queryText,
                                    ],
                                ],
                            ],
                        ],
                        [
                            'nested' => [
                                'path' => Field::FIELD_ATTRIBUTES,
                                'query' => [
                                    'match' => [
                                        Field::FIELD_ATTRIBUTES . '.' . Field::FIELD_ATTRIBUTE_VALUES => $queryText,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
        ];
    }

    /**
     * Approximate k-NN vector query with an efficient pre-filter (OpenSearch
     * Lucene-engine k-NN filter syntax, 2.9+) so store/enabled scoping does
     * not require a post-filter over the whole result set.
     *
     * @param list<float> $queryVector
     *
     * @return array<string, mixed>
     */
    public function vector(int $storeId, array $queryVector, int $size): array
    {
        return [
            'size' => $size,
            '_source' => self::SOURCE_FIELDS,
            'query' => [
                'knn' => [
                    Field::FIELD_EMBEDDING => [
                        'vector' => $queryVector,
                        'k' => $size,
                        'filter' => [
                            'bool' => [
                                'filter' => $this->scopeFilters($storeId),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scopeFilters(int $storeId): array
    {
        return [
            ['term' => [Field::FIELD_STORE_ID => (string)$storeId]],
            ['term' => [Field::FIELD_IS_ENABLED => true]],
        ];
    }
}
