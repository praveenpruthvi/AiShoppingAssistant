<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

final class IncrementalReconciliationCheckpoint
{
    public function __construct(
        private readonly int $lastProductId
    ) {
        if ($lastProductId < 0) {
            throw new \InvalidArgumentException('Last product id cannot be negative.');
        }
    }

    public function lastProductId(): int
    {
        return $this->lastProductId;
    }
}
