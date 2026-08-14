<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

final class CategoryReferenceResolver implements CategoryReferenceResolverInterface
{
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function resolve(StoreScopeInterface $scope, array $categoryIds): array
    {
        $ids = $this->normalizeIds($categoryIds);

        if ($ids === []) {
            return [];
        }

        $rootCategoryId = (int) $this->storeManager->getStore($scope->storeId())->getRootCategoryId();

        $loaded = $this->loadCategories($scope, $ids);

        $ancestorIds = [];
        foreach ($ids as $id) {
            $category = $loaded[$id] ?? null;
            if ($category === null) {
                continue;
            }
            foreach ($this->pathSegments($category, $rootCategoryId) as $segmentId) {
                if (!isset($loaded[$segmentId])) {
                    $ancestorIds[$segmentId] = true;
                }
            }
        }
        unset($ancestorIds[1], $ancestorIds[$rootCategoryId]);
        $ancestorIds = array_keys($ancestorIds);
        sort($ancestorIds);

        if ($ancestorIds !== []) {
            foreach ($this->loadCategories($scope, $ancestorIds) as $id => $category) {
                $loaded[$id] = $category;
            }
        }

        $references = [];
        foreach ($ids as $id) {
            $category = $loaded[$id] ?? null;
            if ($category === null || (int) $category->getIsActive() !== 1) {
                continue;
            }

            $name = trim((string) $category->getName());
            if ($name === '') {
                continue;
            }

            $references[] = $this->buildReference($category, $rootCategoryId, $loaded);
        }

        return $references;
    }

    /**
     * @param array<int, CategoryInterface> $loaded
     */
    private function buildReference(CategoryInterface $category, int $rootCategoryId, array $loaded): CategoryReference
    {
        $name = trim((string) $category->getName());

        $labels = [];
        foreach ($this->pathSegments($category, $rootCategoryId) as $segmentId) {
            $segment = $loaded[$segmentId] ?? null;
            $label = $segment !== null && (int) $segment->getIsActive() === 1
                ? trim((string) $segment->getName())
                : '';
            $labels[] = $label !== '' ? $label : (string) $segmentId;
        }

        $path = $labels !== [] ? implode(' / ', $labels) : $name;

        return new CategoryReference((int) $category->getId(), $name, $path);
    }

    /**
     * @return list<int>
     */
    private function pathSegments(CategoryInterface $category, int $rootCategoryId): array
    {
        $segments = [];

        foreach (explode('/', (string) $category->getPath()) as $part) {
            $segmentId = (int) $part;
            if ($segmentId > 0 && $segmentId !== 1 && $segmentId !== $rootCategoryId) {
                $segments[] = $segmentId;
            }
        }

        return $segments;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, CategoryInterface>
     */
    private function loadCategories(StoreScopeInterface $scope, array $ids): array
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($scope->storeId());
        $collection->addAttributeToSelect(['name', 'path', 'is_active']);
        $collection->addIdFilter($ids);
        $collection->load();

        $loaded = [];
        foreach ($collection->getItems() as $category) {
            $loaded[(int) $category->getId()] = $category;
        }

        return $loaded;
    }

    /**
     * @param list<int> $categoryIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $categoryIds): array
    {
        $ids = [];
        foreach ($categoryIds as $categoryId) {
            if (is_int($categoryId) && $categoryId > 0) {
                $ids[] = $categoryId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}