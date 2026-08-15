<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

interface IncrementalReconciliationCheckpointInterface
{
    public function current(): IncrementalReconciliationCheckpoint;

    public function advance(int $lastProductId): void;

    public function completePass(): void;

    public function requestFullPass(): void;
}
