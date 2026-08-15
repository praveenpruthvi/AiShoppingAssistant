<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;

final class IncrementalProductReconciliationCron
{
    public function __construct(
        private readonly IncrementalProductReconciliationInterface $reconciliation
    ) {
    }

    public function execute(): void
    {
        $this->reconciliation->reconcile();
    }
}
