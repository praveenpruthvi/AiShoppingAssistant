<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalChangeCaptureException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Framework\Indexer\IndexerRegistry;

class ProductChangeScheduler
{
    public const INDEXER_ID = 'ai_product_rag';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly IncrementalProductIndexSchedulerInterface $scheduler
    ) {
    }

    public function scheduleProductIfUpdateOnSave(mixed $productId): void
    {
        $this->scheduleProductsIfUpdateOnSave([$productId]);
    }

    /**
     * @param array<mixed> $productIds
     */
    public function scheduleProductsIfUpdateOnSave(array $productIds): void
    {
        $ids = $this->positiveProductIds($productIds);

        if ($ids === [] || $this->isScheduledMode()) {
            return;
        }

        try {
            $this->scheduler->scheduleMany($ids);
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new IncrementalChangeCaptureException();
        }
    }

    private function isScheduledMode(): bool
    {
        try {
            return (bool)$this->indexerRegistry->get(self::INDEXER_ID)->isScheduled();
        } catch (\Throwable) {
            throw new IncrementalChangeCaptureException();
        }
    }

    /**
     * @param array<mixed> $productIds
     *
     * @return list<int>
     */
    private function positiveProductIds(array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $productId) {
            $ids[] = $this->positiveProductId($productId);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
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
