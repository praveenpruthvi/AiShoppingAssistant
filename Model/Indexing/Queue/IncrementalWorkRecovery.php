<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkRecoveryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Magento\Framework\Lock\LockManagerInterface;

final class IncrementalWorkRecovery implements IncrementalWorkRecoveryInterface
{
    private const LOCK_NAME = 'aavirbhava_ai_incremental_work_recovery';
    private const BATCH_LIMIT = 50;
    private const WAKEUP_VISIBILITY_SECONDS = 300;

    public function __construct(
        private readonly IncrementalWorkLedgerInterface $ledger,
        private readonly MagentoIncrementalProductIndexScheduler $queuePublisher,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function recover(): int
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return 0;
        }

        try {
            $this->ledger->recoverExpiredLeases(self::BATCH_LIMIT);
            $published = 0;

            foreach ($this->ledger->dueProductIds(self::BATCH_LIMIT) as $productId) {
                $claim = $this->ledger->markQueuedForWakeup($productId, self::WAKEUP_VISIBILITY_SECONDS);
                if ($claim === null) {
                    continue;
                }

                try {
                    $this->queuePublisher->schedule($productId);
                    ++$published;
                } catch (IncrementalQueuePublishFailedException $exception) {
                    if (!$this->ledger->releaseQueuedWakeup($claim)) {
                        throw new IncrementalLedgerPersistenceException();
                    }
                    throw $exception;
                }
            }

            return $published;
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
