<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalReconciliationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Framework\Lock\LockManagerInterface;

final class IncrementalProductReconciliation implements IncrementalProductReconciliationInterface
{
    private const LOCK_NAME = 'aavirbhava_ai_incremental_product_reconciliation';
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 1000;

    public function __construct(
        private readonly IncrementalReconciliationCheckpointInterface $checkpoint,
        private readonly ProductIdCursorBatchProviderInterface $productIds,
        private readonly IncrementalProductIndexSchedulerInterface $scheduler,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function reconcile(int $limit = 50): int
    {
        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw new IncrementalReconciliationException();
        }

        try {
            $locked = $this->lockManager->lock(self::LOCK_NAME, 0);
        } catch (\Throwable) {
            throw new IncrementalReconciliationException();
        }

        if (!$locked) {
            return 0;
        }

        $primaryFailure = null;
        $releaseFailed = false;
        $scheduled = 0;

        try {
            $scheduled = $this->reconcileLocked($limit);
        } catch (\Throwable $throwable) {
            $primaryFailure = $throwable;
        } finally {
            try {
                $releaseFailed = !$this->lockManager->unlock(self::LOCK_NAME);
            } catch (\Throwable) {
                $releaseFailed = true;
            }
        }

        if ($primaryFailure instanceof ProductIndexingException) {
            throw $primaryFailure;
        }

        if ($primaryFailure !== null || $releaseFailed) {
            throw new IncrementalReconciliationException();
        }

        return $scheduled;
    }

    public function requestFullPass(): void
    {
        $this->checkpoint->requestFullPass();
    }

    private function reconcileLocked(int $limit): int
    {
        $cursor = $this->checkpoint->current();
        $ids = $this->productIds->idsAfter($cursor->lastProductId(), $limit);

        if ($ids === []) {
            $this->checkpoint->completePass();
            return 0;
        }

        $this->scheduler->scheduleMany($ids);

        if (count($ids) < $limit) {
            $this->checkpoint->completePass();
        } else {
            $this->checkpoint->advance($ids[count($ids) - 1]);
        }

        return count($ids);
    }
}
