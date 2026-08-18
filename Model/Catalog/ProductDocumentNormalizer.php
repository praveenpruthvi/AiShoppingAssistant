<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentNormalizerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductIndexEligibilityPolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductNormalizationResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\UntrustedContentSanitizerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Turns a raw, untrusted catalogue snapshot into an eligible, sanitized document.
 *
 * Pipeline: eligibility gate, per-field sanitization with policy filtering,
 * empty-value pruning, deterministic ordering, searchable-text assembly, and
 * content hashes. For the same snapshot the output is byte-for-byte identical.
 */
final class ProductDocumentNormalizer implements ProductDocumentNormalizerInterface
{
    public function __construct(
        private readonly ProductIndexEligibilityPolicyInterface $eligibilityPolicy,
        private readonly UntrustedContentSanitizerInterface $sanitizer,
        private readonly ProductAttributePolicyInterface $attributePolicy,
        private readonly ContentHashServiceInterface $hashService
    ) {
    }

    public function normalize(
        ProductSnapshotInterface $snapshot,
        ProductEligibilityContextInterface $context
    ): ProductNormalizationResultInterface {
        $eligibility = $this->eligibilityPolicy->evaluate($snapshot, $context);

        if (!$eligibility->eligible()) {
            return new ProductNormalizationResult(false, $eligibility->reasonCode(), null);
        }

        $sku = $this->sanitizeField($snapshot->sku(), UntrustedContentSanitizerInterface::MAX_SKU_CHARACTERS);
        if ($sku === '') {
            throw new CatalogException(__('The product SKU is empty after sanitization.'));
        }

        $name = $this->sanitizeField($snapshot->name(), UntrustedContentSanitizerInterface::MAX_PRODUCT_NAME_CHARACTERS);
        if ($name === '') {
            throw new CatalogException(__('The product name is empty after sanitization.'));
        }

        $shortDescription = $this->sanitizeField(
            $snapshot->shortDescription(),
            UntrustedContentSanitizerInterface::MAX_SHORT_DESCRIPTION_CHARACTERS
        );

        $longDescription = $this->sanitizeField(
            $snapshot->longDescription(),
            UntrustedContentSanitizerInterface::MAX_LONG_DESCRIPTION_CHARACTERS
        );

        $categories = $this->normalizeCategories($snapshot->categories());
        $attributes = $this->normalizeAttributes($snapshot->attributes());
        $websiteIds = $snapshot->websiteIds();
        sort($websiteIds);

        $parts = [
            $name,
            $shortDescription,
            $longDescription,
        ];

        foreach ($categories as $category) {
            $parts[] = $category->name();
            $parts[] = $category->path();
        }

        foreach ($attributes as $attribute) {
            $parts[] = $attribute->label();
            foreach ($attribute->values() as $value) {
                $parts[] = $value;
            }
        }

        $searchableText = $this->assembleSearchableText($parts);

        $categoryPayload = [];
        foreach ($categories as $category) {
            $categoryPayload[] = [
                'categoryId' => $category->categoryId(),
                'name' => $category->name(),
                'path' => $category->path(),
            ];
        }

        $attributePayload = [];
        foreach ($attributes as $attribute) {
            $attributePayload[] = [
                'code' => $attribute->code(),
                'label' => $attribute->label(),
                'values' => $attribute->values(),
            ];
        }

        $embeddingPayload = [
            'schemaVersion' => ProductDocumentSchema::VERSION,
            'name' => $name,
            'shortDescription' => $shortDescription,
            'longDescription' => $longDescription,
            'categories' => $categoryPayload,
            'attributes' => $attributePayload,
            'searchableText' => $searchableText,
        ];

        $completePayload = [
            'schemaVersion' => ProductDocumentSchema::VERSION,
            'documentId' => $this->buildDocumentId($snapshot->storeId(), $snapshot->entityId()),
            'entityId' => $snapshot->entityId(),
            'sku' => $sku,
            'storeId' => $snapshot->storeId(),
            'websiteIds' => $websiteIds,
            'productType' => $snapshot->productType(),
            'name' => $name,
            'shortDescription' => $shortDescription,
            'longDescription' => $longDescription,
            'isEnabled' => $snapshot->isEnabled(),
            'visibility' => $snapshot->visibility(),
            'categories' => $categoryPayload,
            'attributes' => $attributePayload,
            'searchableText' => $searchableText,
        ];

        $document = new ProductDocument(
            ProductDocumentSchema::VERSION,
            $this->buildDocumentId($snapshot->storeId(), $snapshot->entityId()),
            $snapshot->entityId(),
            $sku,
            $snapshot->storeId(),
            $websiteIds,
            $snapshot->productType(),
            $name,
            $shortDescription,
            $longDescription,
            $snapshot->isEnabled(),
            $snapshot->visibility(),
            $categories,
            $attributes,
            $searchableText,
            $this->hashService->hash($embeddingPayload),
            $this->hashService->hash($completePayload),
            $this->formatUpdatedAt($snapshot->updatedAt())
        );

        return new ProductNormalizationResult(true, ProductEligibilityResultInterface::REASON_ELIGIBLE, $document);
    }

    /**
     * ProductSnapshotInterface::updatedAt() carries Magento's own raw
     * `catalog_product_entity.updated_at` value verbatim — MySQL DATETIME
     * format (`Y-m-d H:i:s`, no timezone marker; Magento stores timestamps
     * in UTC by convention). ProductIndexMapping declares this field as
     * OpenSearch `date` type with strict ISO-8601 formats
     * (strict_date_time_no_millis / strict_date_optional_time /
     * epoch_millis) — the raw MySQL string satisfies none of them, and
     * OpenSearch rejects the whole document at bulk-index time
     * (mapper_parsing_exception), a real, confirmed live-testing finding,
     * not a hypothetical. This is the only consumer of
     * ProductSnapshotInterface::updatedAt() (confirmed by inspection), so
     * converting here — rather than at the snapshot layer, which has no
     * reason to know about OpenSearch's date format at all — keeps this
     * concern localized to the class actually shaping data for the index.
     */
    private function formatUpdatedAt(?string $mysqlDateTime): ?string
    {
        if ($mysqlDateTime === null || $mysqlDateTime === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            return null;
        }

        return $parsed->format(DATE_ATOM);
    }

    /**
     * @param list<CategoryReferenceInterface> $categories
     *
     * @return list<CategoryReferenceInterface>
     */
    private function normalizeCategories(array $categories): array
    {
        $normalized = [];

        foreach ($categories as $category) {
            $name = $this->sanitizeField(
                $category->name(),
                UntrustedContentSanitizerInterface::MAX_CATEGORY_CHARACTERS
            );
            $path = $this->sanitizeField(
                $category->path(),
                UntrustedContentSanitizerInterface::MAX_CATEGORY_CHARACTERS
            );

            if ($name === '') {
                continue;
            }

            $normalized[$category->categoryId()] = new CategoryReference(
                $category->categoryId(),
                $name,
                $path === '' ? $name : $path
            );
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param list<SearchableAttributeInterface> $attributes
     *
     * @return list<SearchableAttributeInterface>
     */
    private function normalizeAttributes(array $attributes): array
    {
        $keyed = [];

        foreach ($attributes as $attribute) {
            $keyed[$attribute->code()] = $attribute;
        }

        $filtered = $this->attributePolicy->filter($keyed);
        $normalized = [];

        foreach ($filtered as $attribute) {
            $label = $this->sanitizeField(
                $attribute->label(),
                UntrustedContentSanitizerInterface::MAX_ATTRIBUTE_LABEL_CHARACTERS
            );

            if ($label === '') {
                continue;
            }

            $values = [];
            foreach ($attribute->values() as $value) {
                $sanitized = $this->sanitizeField(
                    $value,
                    UntrustedContentSanitizerInterface::MAX_ATTRIBUTE_VALUE_CHARACTERS
                );
                if ($sanitized !== '' && !in_array($sanitized, $values, true)) {
                    $values[] = $sanitized;
                }
            }

            if ($values === []) {
                continue;
            }

            $normalized[] = new SearchableAttribute($attribute->code(), $label, $values);
        }

        return $normalized;
    }

    /**
     * @param list<string> $parts
     */
    private function assembleSearchableText(array $parts): string
    {
        $unique = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (!in_array($part, $unique, true)) {
                $unique[] = $part;
            }
        }

        $text = implode(' ', $unique);

        return mb_substr($text, 0, UntrustedContentSanitizerInterface::MAX_SEARCHABLE_TEXT_CHARACTERS);
    }

    private function sanitizeField(string $text, int $maxCharacters): string
    {
        return mb_substr($this->sanitizer->sanitize($text), 0, $maxCharacters);
    }

    private function buildDocumentId(int $storeId, int $entityId): string
    {
        return sprintf('%d_%d', $storeId, $entityId);
    }
}
