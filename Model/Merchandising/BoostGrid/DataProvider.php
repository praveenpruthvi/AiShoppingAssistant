<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising\BoostGrid;

use Aavirbhava\AiShoppingAssistant\Model\MerchandisingBoost;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Backs the standalone "Merchandising Boosts" Ui Component grid
 * (view/adminhtml/ui_component/aavirbhava_boost_listing.xml). Reads
 * directly off Model\ResourceModel\MerchandisingBoost\Collection — the
 * same collection/resource/table the mass-action save flow's repository
 * writes through, so the grid always reflects exactly what was saved,
 * with no separate read path to drift out of sync.
 */
class DataProvider extends AbstractDataProvider
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $result = [];
        foreach ($this->collection->getItems() as $item) {
            /** @var MerchandisingBoost $item */
            $result[$item->getId()] = $item->getData();
        }

        return ['items' => array_values($result), 'totalRecords' => $this->collection->getSize()];
    }
}
