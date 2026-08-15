<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalWorkerLockException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Magento queue consumer for one incremental product-index message.
 *
 * The queue message is only a wake-up. Durable completion, retry, or terminal
 * state is recorded in the ledger before returning from handler failures.
 */
final class IncrementalProductIndexConsumer
{
    private const REBUILD_GATE_LOCK_NAME = 'aavirbhava_ai_full_rebuild_gate';

    public function __construct(
        private readonly ProductIncrementalIndexerInterface $indexer,
        private readonly IncrementalWorkLedgerInterface $ledger,
        private readonly IncrementalFailureDispositionPolicyInterface $failurePolicy,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function process(mixed $productId): void
    {
        $id = $this->positiveProductId($productId);
        $lockName = $this->productLockName($id);

        try {
            $locked = $this->lockManager->lock($lockName, 0);
        } catch (\Throwable) {
            throw new IncrementalWorkerLockException();
        }

        if (!$locked) {
            return;
        }

        $primaryFailure = null;
        $releaseFailed = false;

        try {
            $this->processLocked($id);
        } catch (\Throwable $throwable) {
            $primaryFailure = $throwable;
        } finally {
            try {
                $released = $this->lockManager->unlock($lockName);
                $releaseFailed = !$released;
            } catch (\Throwable) {
                $releaseFailed = true;
            }
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }

        if ($releaseFailed) {
            throw new IncrementalWorkerLockException();
        }
    }

    private function processLocked(int $id): void
    {
        $claim = $this->claimUnderRebuildGate($id);

        if ($claim === null) {
            return;
        }

        try {
            $this->indexer->process($id);
        } catch (\Throwable $throwable) {
            $this->recordIndexingFailure($claim, $throwable);
            return;
        }

        if (!$this->ledger->complete($claim)) {
            throw new IncrementalLedgerPersistenceException();
        }
    }

    private function claimUnderRebuildGate(int $id): ?IncrementalWorkClaimInterface
    {
        try {
            $locked = $this->lockManager->lock(self::REBUILD_GATE_LOCK_NAME, 0);
        } catch (\Throwable) {
            throw new IncrementalWorkerLockException();
        }

        if (!$locked) {
            return null;
        }

        $primaryFailure = null;
        $releaseFailed = false;
        $claim = null;

        try {
            $claim = $this->ledger->claimDueWork($id);
        } catch (\Throwable $throwable) {
            $primaryFailure = $throwable;
        } finally {
            try {
                $released = $this->lockManager->unlock(self::REBUILD_GATE_LOCK_NAME);
                $releaseFailed = !$released;
            } catch (\Throwable) {
                $releaseFailed = true;
            }
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }

        if ($releaseFailed) {
            throw new IncrementalWorkerLockException();
        }

        return $claim;
    }

    private function recordIndexingFailure(IncrementalWorkClaimInterface $claim, \Throwable $throwable): void
    {
        $disposition = $this->failurePolicy->classify($throwable, $claim->attempts());
        $recorded = $disposition->retryable()
            ? $this->ledger->recordRetry($claim, $disposition->errorCode(), $disposition->delaySeconds())
            : $this->ledger->recordTerminal($claim, $disposition->errorCode());

        if (!$recorded) {
            throw new IncrementalLedgerPersistenceException();
        }
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

    private function productLockName(int $productId): string
    {
        return 'aavirbhava_ai_incremental_product_' . $productId;
    }
}
