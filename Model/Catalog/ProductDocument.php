<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductDocument implements ProductDocumentInterface
{
    /**
     * @param list<int>                          $websiteIds
     * @param list<CategoryReferenceInterface>   $categories
     * @param list<SearchableAttributeInterface> $attributes
     */
    public function __construct(
        private int $schemaVersion,
        private string $documentId,
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
        private string $searchableText,
        private string $embeddingContentHash,
        private string $completeDocumentHash,
        private ?string $updatedAt = null
    ) {
        if ($schemaVersion < 1) {
            throw new CatalogException(__('Schema version must be a positive integer.'));
        }

        if ($documentId === '') {
            throw new CatalogException(__('Document id must not be empty.'));
        }

        if ($entityId < 1) {
            throw new CatalogException(__('Document entity id must be a positive integer.'));
        }

        if ($sku === '') {
            throw new CatalogException(__('Document SKU must not be empty.'));
        }

        if ($storeId < 1) {
            throw new CatalogException(__('Document store id must be a positive integer.'));
        }

        if ($websiteIds === []) {
            throw new CatalogException(__('Document must be assigned to at least one website.'));
        }

        foreach ($websiteIds as $websiteId) {
            if (!is_int($websiteId) || $websiteId < 1) {
                throw new CatalogException(__('Document website ids must be positive integers.'));
            }
        }

        if ($productType === '') {
            throw new CatalogException(__('Document product type must not be empty.'));
        }

        if ($name === '') {
            throw new CatalogException(__('Document product name must not be empty.'));
        }

        if ($searchableText === '') {
            throw new CatalogException(__('Document searchable text must not be empty.'));
        }

        if (
            preg_match(self::HASH_PATTERN, $embeddingContentHash) !== 1
            || preg_match(self::HASH_PATTERN, $completeDocumentHash) !== 1
        ) {
            throw new CatalogException(__('Document content hashes must be SHA-256 hex digests.'));
        }
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function documentId(): string
    {
        return $this->documentId;
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

    public function searchableText(): string
    {
        return $this->searchableText;
    }

    public function embeddingContentHash(): string
    {
        return $this->embeddingContentHash;
    }

    public function completeDocumentHash(): string
    {
        return $this->completeDocumentHash;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }
}
