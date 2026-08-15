<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductIdBatchProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use InvalidArgumentException;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Data\Collection;

final class ProductIdBatchProvider implements ProductIdBatchProviderInterface
{
    public const MIN_BATCH_SIZE = 1;
    public const MAX_BATCH_SIZE = 1000;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    public function batches(StoreScopeInterface $scope, int $batchSize): iterable
    {
        if ($batchSize < self::MIN_BATCH_SIZE || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                sprintf('Batch size must be between %d and %d.', self::MIN_BATCH_SIZE, self::MAX_BATCH_SIZE)
            );
        }

        $lastId = 0;

        while (true) {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($scope->storeId());
            $collection->addWebsiteFilter($scope->websiteId());
            $collection->addFieldToFilter('entity_id', ['gt' => $lastId]);
            $collection->setOrder('entity_id', Collection::SORT_ORDER_ASC);
            $collection->setPageSize($batchSize);
            $collection->setCurPage(1);

            $ids = $collection->getAllIds($batchSize, 0);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);

            if ($ids === []) {
                return;
            }

            yield $ids;

            $lastId = $ids[count($ids) - 1];

            if (count($ids) < $batchSize) {
                return;
            }
        }
    }
}
