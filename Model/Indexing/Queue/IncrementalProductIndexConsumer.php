<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;

/**
 * Magento queue consumer for one incremental product-index message.
 *
 * Ordinary indexing failures intentionally propagate from this handler.
 * Magento's default consumer rejects ordinary handler exceptions without
 * requeueing, so propagation prevents acknowledgement but is not sufficient
 * durable retry semantics on its own.
 */
final class IncrementalProductIndexConsumer
{
    public function __construct(
        private readonly ProductIncrementalIndexerInterface $indexer
    ) {
    }

    public function process(mixed $productId): void
    {
        $this->indexer->process($this->positiveProductId($productId));
    }

    private function positiveProductId(mixed $productId): int
    {
        if (is_int($productId) && $productId > 0) {
            return $productId;
        }

        if (!is_string($productId) || !preg_match('/^[1-9][0-9]*$/', $productId)) {
            throw new InvalidProductIndexEntityIdsException();
        }

        $value = filter_var(
            $productId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidProductIndexEntityIdsException();
    }
}
