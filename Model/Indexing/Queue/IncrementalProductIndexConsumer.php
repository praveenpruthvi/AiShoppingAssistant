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

        try {
            $this->processLocked($id);
        } catch (\Throwable $throwable) {
            $primaryFailure = $throwable;
            throw $throwable;
        } finally {
            try {
                $this->lockManager->unlock($lockName);
            } catch (\Throwable) {
                if ($primaryFailure === null) {
                    throw new IncrementalWorkerLockException();
                }
            }
        }
    }

    private function processLocked(int $id): void
    {
        $claim = $this->ledger->claimDueWork($id);

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
