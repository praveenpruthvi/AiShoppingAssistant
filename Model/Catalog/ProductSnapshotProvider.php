<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductRatingResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeValueResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\DataObject;

final class ProductSnapshotProvider implements ProductSnapshotProviderInterface
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryReferenceResolverInterface $categoryReferenceResolver,
        private readonly SearchableAttributeValueResolverInterface $searchableAttributeValueResolver,
        private readonly ProductRatingResolverInterface $productRatingResolver
    ) {
    }

    public function load(
        StoreScopeInterface $scope,
        IndexingConfigInterface $config,
        array $productIds
    ): ProductSnapshotBatchInterface {
        $ids = $this->normalizeIds($productIds);

        if ($ids === []) {
            return new ProductSnapshotBatch([], []);
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($scope->storeId());
        $collection->addWebsiteFilter($scope->websiteId());
        $collection->addIdFilter($ids);
        $collection->addAttributeToSelect('name');
        $collection->addAttributeToSelect('status');
        $collection->addAttributeToSelect('visibility');

        if ($config->includeShortDescription()) {
            $collection->addAttributeToSelect('short_description');
        }

        if ($config->includeLongDescription()) {
            $collection->addAttributeToSelect('description');
        }

        foreach ($config->searchableAttributeCodes() as $code) {
            $collection->addAttributeToSelect($code);
        }

        $this->productRatingResolver->appendToCollection($collection, $scope);

        $collection->setOrder('entity_id', Collection::SORT_ORDER_ASC);
        $collection->setPageSize(count($ids));
        $collection->setCurPage(1);
        $collection->load();

        $collection->addCategoryIds();

        $items = array_values($collection->getItems());

        $categories = $this->loadCategoryReferences($scope, $items);
        $catalogRatingAverage = $this->productRatingResolver->catalogAverage($scope);

        $foundIds = [];
        foreach ($items as $product) {
            $foundIds[] = (int) $product->getId();
        }

        $snapshots = [];
        foreach ($items as $product) {
            $snapshots[] = $this->snapshotFor($scope, $config, $product, $categories, $catalogRatingAverage);
        }

        $missing = array_values(array_diff($ids, $foundIds));

        return new ProductSnapshotBatch($snapshots, $missing);
    }

    /**
     * @param list<ProductInterface> $products
     *
     * @return list<CategoryReferenceInterface> sorted by category id ascending
     */
    private function loadCategoryReferences(StoreScopeInterface $scope, array $products): array
    {
        $categoryIds = [];
        foreach ($products as $product) {
            foreach ($this->rawData($product, 'category_ids') ?? [] as $categoryId) {
                $categoryIds[] = (int) $categoryId;
            }
        }

        $categoryIds = array_values(array_unique($categoryIds));
        sort($categoryIds);

        return $this->categoryReferenceResolver->resolve($scope, $categoryIds);
    }

    /**
     * @param list<CategoryReferenceInterface> $categories
     */
    private function snapshotFor(
        StoreScopeInterface $scope,
        IndexingConfigInterface $config,
        ProductInterface $product,
        array $categories,
        float $catalogRatingAverage
    ): ProductSnapshot {
        $categoryByRef = [];
        foreach ($categories as $category) {
            $categoryByRef[$category->categoryId()] = $category;
        }

        $productCategories = [];
        foreach ($this->rawData($product, 'category_ids') ?? [] as $categoryId) {
            $category = $categoryByRef[(int) $categoryId] ?? null;
            if ($category !== null) {
                $productCategories[] = $category;
            }
        }

        $attributes = $this->searchableAttributeValueResolver->resolve($scope, $config, $product);

        return new ProductSnapshot(
            (int) $product->getId(),
            (string) $product->getSku(),
            $scope->storeId(),
            [$scope->websiteId()],
            (string) $product->getTypeId(),
            (string) $product->getName(),
            $config->includeShortDescription() ? (string) ($this->rawData($product, 'short_description') ?? '') : '',
            $config->includeLongDescription() ? (string) ($this->rawData($product, 'description') ?? '') : '',
            (int) $product->getStatus() === Status::STATUS_ENABLED,
            (int) $product->getVisibility(),
            $productCategories,
            $attributes,
            $product->getUpdatedAt() !== null ? (string) $product->getUpdatedAt() : null,
            $this->productRatingResolver->percentToStars((float) ($this->rawData($product, 'rating_summary') ?? 0)),
            (int) ($this->rawData($product, 'reviews_count') ?? 0),
            $catalogRatingAverage
        );
    }

    private function rawData(ProductInterface $product, string $key): mixed
    {
        return $product instanceof DataObject ? $product->getData($key) : null;
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $productId) {
            if (is_int($productId) && $productId > 0) {
                $ids[] = $productId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
