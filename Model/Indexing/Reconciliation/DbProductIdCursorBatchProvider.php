<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalReconciliationException;
use Magento\Framework\App\ResourceConnection;

final class DbProductIdCursorBatchProvider implements ProductIdCursorBatchProviderInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function idsAfter(int $lastProductId, int $limit): array
    {
        if ($lastProductId < 0 || $limit < 1 || $limit > 1000) {
            throw new IncrementalReconciliationException();
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                    ->where('entity_id > ?', $lastProductId)
                    ->order('entity_id ASC')
                    ->limit($limit)
            );
        } catch (\Throwable) {
            throw new IncrementalReconciliationException();
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = filter_var(
                $row,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
            );
            if (!is_int($id)) {
                throw new IncrementalReconciliationException();
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
