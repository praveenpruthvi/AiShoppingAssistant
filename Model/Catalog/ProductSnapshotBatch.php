<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductSnapshotBatch implements ProductSnapshotBatchInterface
{
    /**
     * @param list<ProductSnapshotInterface> $snapshots
     * @param list<int>                      $missingProductIds
     */
    public function __construct(
        private array $snapshots,
        private array $missingProductIds
    ) {
        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof ProductSnapshotInterface) {
                throw new CatalogException(__('Product snapshot batch contains an invalid entry.'));
            }
        }

        foreach ($missingProductIds as $productId) {
            if (!is_int($productId) || $productId < 1) {
                throw new CatalogException(__('Missing product ids must be positive integers.'));
            }
        }
    }

    public function snapshots(): array
    {
        return $this->snapshots;
    }

    public function missingProductIds(): array
    {
        return $this->missingProductIds;
    }
}