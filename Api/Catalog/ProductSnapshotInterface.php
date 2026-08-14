<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Immutable snapshot of raw catalogue data before normalization.
 *
 * The snapshot is untrusted input. It contains no price, stock, salability, URL,
 * or customer-group data by design; those facts are always resolved from Magento
 * services at retrieval or display time.
 */
interface ProductSnapshotInterface
{
    /**
     * Positive Magento product entity id.
     *
     * @throws CatalogException
     */
    public function entityId(): int;

    /**
     * Raw SKU. Normalization is responsible for validating and truncating it.
     *
     * @throws CatalogException
     */
    public function sku(): string;

    /**
     * Positive Magento store view id the snapshot belongs to.
     *
     * @throws CatalogException
     */
    public function storeId(): int;

    /**
     * Non-empty list of positive Magento website ids the product is assigned to.
     *
     * @return list<int>
     *
     * @throws CatalogException
     */
    public function websiteIds(): array;

    /**
     * Non-empty Magento product type, e.g. "simple" or "configurable".
     *
     * @throws CatalogException
     */
    public function productType(): string;

    /**
     * Raw product name (may be empty on badly maintained catalogue data).
     */
    public function name(): string;

    /**
     * Raw short description (may be empty).
     */
    public function shortDescription(): string;

    /**
     * Raw long description (may be empty). This is the highest-trust source for
     * injection attempts and must pass through the sanitizer.
     */
    public function longDescription(): string;

    /**
     * True when the product status attribute is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Raw Magento visibility flag. Eligible values come from
     * Magento\Catalog\Model\Product\Visibility.
     */
    public function visibility(): int;

    /**
     * @return list<CategoryReferenceInterface>
     */
    public function categories(): array;

    /**
     * @return list<SearchableAttributeInterface>
     */
    public function attributes(): array;

    /**
     * Optional catalog updated-at timestamp (W3C/ISO-8601). Used for audit only.
     */
    public function updatedAt(): ?string;
}
