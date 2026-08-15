<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;

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
        private readonly IncrementalFailureDispositionPolicyInterface $failurePolicy
    ) {
    }

    public function process(mixed $productId): void
    {
        $id = $this->positiveProductId($productId);
        $claim = $this->ledger->claimDueWork($id);

        if ($claim === null) {
            return;
        }

        try {
            $this->indexer->process($id);
            $this->ledger->complete($claim);
        } catch (\Throwable $throwable) {
            $disposition = $this->failurePolicy->classify($throwable, 0);
            $recorded = $disposition->retryable()
                ? $this->ledger->recordRetry($claim, $disposition->errorCode(), $disposition->delaySeconds())
                : $this->ledger->recordTerminal($claim, $disposition->errorCode());

            if (!$recorded) {
                throw new IncrementalLedgerPersistenceException();
            }
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
}
