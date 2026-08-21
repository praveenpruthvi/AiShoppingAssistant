<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface as Field;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchResponseInvalidException;

/**
 * Parses a validated search-hit _source (already shape-checked by
 * AssistantSearchClientInterface::search()) into a SearchCandidate.
 *
 * Fails closed on missing/malformed required fields rather than silently
 * substituting defaults for identity fields (entity id, SKU, store id).
 */
final class SearchHitParser
{
    /**
     * @param array<string, mixed> $source
     */
    public function parse(array $source, float $bm25Score, float $vectorScore): SearchCandidate
    {
        $entityId = $source[Field::FIELD_ENTITY_ID] ?? null;
        $sku = $source[Field::FIELD_SKU] ?? null;
        $storeId = $source[Field::FIELD_STORE_ID] ?? null;

        if (!is_int($entityId) || !is_string($sku) || $sku === '') {
            throw new SearchResponseInvalidException();
        }

        if (!is_string($storeId) && !is_int($storeId)) {
            throw new SearchResponseInvalidException();
        }

        $name = $source[Field::FIELD_NAME] ?? '';
        $shortDescription = $source[Field::FIELD_SHORT_DESCRIPTION] ?? '';
        $isEnabled = $source[Field::FIELD_IS_ENABLED] ?? false;
        $visibility = $source[Field::FIELD_VISIBILITY] ?? 0;

        if (!is_string($name) || !is_string($shortDescription) || !is_bool($isEnabled) || !is_int($visibility)) {
            throw new SearchResponseInvalidException();
        }

        return new SearchCandidate(
            $entityId,
            $sku,
            (int)$storeId,
            $name,
            $shortDescription,
            $this->parseCategoryNames($source[Field::FIELD_CATEGORIES] ?? []),
            $this->parseAttributes($source[Field::FIELD_ATTRIBUTES] ?? []),
            $isEnabled,
            $visibility,
            $bm25Score,
            $vectorScore,
            0.0,
            $this->parseNumeric($source[Field::FIELD_RATING_AVERAGE] ?? null, 0.0),
            (int) $this->parseNumeric($source[Field::FIELD_REVIEW_COUNT] ?? null, 0.0),
            $this->parseNumeric($source[Field::FIELD_CATALOG_RATING_AVERAGE] ?? null, 0.0)
        );
    }

    private function parseNumeric(mixed $value, float $default): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * @param mixed $rawCategories
     *
     * @return list<string>
     */
    private function parseCategoryNames(mixed $rawCategories): array
    {
        if (!is_array($rawCategories)) {
            throw new SearchResponseInvalidException();
        }

        $names = [];
        foreach ($rawCategories as $category) {
            if (!is_array($category)) {
                throw new SearchResponseInvalidException();
            }

            $name = $category[Field::FIELD_CATEGORY_NAME] ?? null;
            if (!is_string($name)) {
                throw new SearchResponseInvalidException();
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param mixed $rawAttributes
     *
     * @return list<array{code: string, label: string, values: list<string>}>
     */
    private function parseAttributes(mixed $rawAttributes): array
    {
        if (!is_array($rawAttributes)) {
            throw new SearchResponseInvalidException();
        }

        $attributes = [];
        foreach ($rawAttributes as $attribute) {
            if (!is_array($attribute)) {
                throw new SearchResponseInvalidException();
            }

            $code = $attribute[Field::FIELD_ATTRIBUTE_CODE] ?? null;
            $label = $attribute[Field::FIELD_ATTRIBUTE_LABEL] ?? null;
            $values = $attribute[Field::FIELD_ATTRIBUTE_VALUES] ?? null;

            if (!is_string($code) || !is_string($label) || !is_array($values)) {
                throw new SearchResponseInvalidException();
            }

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new SearchResponseInvalidException();
                }
            }

            $attributes[] = ['code' => $code, 'label' => $label, 'values' => array_values($values)];
        }

        return $attributes;
    }
}
