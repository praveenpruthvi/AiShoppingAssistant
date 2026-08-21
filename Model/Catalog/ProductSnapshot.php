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
        private ?string $updatedAt = null,
        private float $ratingAverage = 0.0,
        private int $reviewCount = 0,
        private float $catalogRatingAverage = 0.0
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

        foreach (['ratingAverage' => $ratingAverage, 'catalogRatingAverage' => $catalogRatingAverage] as $rating) {
            if ($rating < 0.0 || $rating > 5.0) {
                throw new CatalogException(__('A product rating average must be between 0 and 5.'));
            }
        }

        if ($reviewCount < 0) {
            throw new CatalogException(__('Product review count must not be negative.'));
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

    public function ratingAverage(): float
    {
        return $this->ratingAverage;
    }

    public function reviewCount(): int
    {
        return $this->reviewCount;
    }

    public function catalogRatingAverage(): float
    {
        return $this->catalogRatingAverage;
    }
}
