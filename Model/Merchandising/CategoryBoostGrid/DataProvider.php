<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostGrid;

use Aavirbhava\AiShoppingAssistant\Model\CategoryBoost;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Backs the standalone "Category Boosts" Ui Component grid
 * (view/adminhtml/ui_component/aavirbhava_categoryboost_listing.xml).
 * Reads directly off Model\ResourceModel\CategoryBoost\Collection — the
 * same collection/resource/table CategoryBoostRepository writes through
 * — mirrors Merchandising\BoostGrid\DataProvider exactly, with one
 * addition: category names are resolved separately here (via a real,
 * scoped category collection, `addNameToResult()`), never a SQL join —
 * see CategoryBoost\Collection's own docblock for why a join isn't
 * available the way MerchandisingBoost\Collection's product-SKU join is.
 */
class DataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $result = [];
        $categoryIds = [];
        foreach ($this->collection->getItems() as $item) {
            /** @var CategoryBoost $item */
            $data = $item->getData();
            $result[$item->getId()] = $data;
            $categoryIds[] = (int) $data['category_id'];
        }

        $names = $this->categoryNames(array_values(array_unique($categoryIds)));
        foreach ($result as $boostId => $data) {
            $result[$boostId]['category_name'] = $names[(int) $data['category_id']] ?? (string) __('(category no longer exists)');
        }

        return ['items' => array_values($result), 'totalRecords' => $this->collection->getSize()];
    }

    /**
     * @param list<int> $categoryIds
     *
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
        $collection->addNameToResult();

        $names = [];
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
        }

        return $names;
    }
}
