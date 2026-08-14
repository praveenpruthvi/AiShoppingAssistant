<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductSnapshot implements ProductSnapshotInterface
{
    /**
     * @param list<int>                              $websiteIds
     * @param list<CategoryReferenceInterface>       $categories
     * @param list<SearchableAttributeInterface>     $attributes
     */
    public function __construct(
        private int $entityId,
        private string $sku,
        private int $storeId,
        private array $websiteIds,
        private string $productType,
        private string $name,
        private string $shortDescription,
        private string $longDescription,
        private bool $isEnabled,
        private int $visibility,
        private array $categories,
        private array $attributes,
        private ?string $updatedAt = null
    ) {
        if ($entityId < 1) {
            throw new CatalogException(__('Product entity id must be a positive integer.'));
        }

        if ($sku === '') {
            throw new CatalogException(__('Product SKU must not be empty.'));
        }

        if ($storeId < 1) {
            throw new CatalogException(__('Product store id must be a positive integer.'));
        }

        if ($websiteIds === []) {
            throw new CatalogException(__('Product must be assigned to at least one website.'));
        }

        foreach ($websiteIds as $websiteId) {
            if (!is_int($websiteId) || $websiteId < 1) {
                throw new CatalogException(__('Website ids must be positive integers.'));
            }
        }

        if ($productType === '') {
            throw new CatalogException(__('Product type must not be empty.'));
        }
    }

    public function entityId(): int
    {
        return $this->entityId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function websiteIds(): array
    {
        return $this->websiteIds;
    }

    public function productType(): string
    {
        return $this->productType;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function shortDescription(): string
    {
        return $this->shortDescription;
    }

    public function longDescription(): string
    {
        return $this->longDescription;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function visibility(): int
    {
        return $this->visibility;
    }

    public function categories(): array
    {
        return $this->categories;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }
}
