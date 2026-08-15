<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

final class IndirectCatalogueChangeObserver implements ObserverInterface
{
    public function __construct(
        private readonly IncrementalProductReconciliationInterface $reconciliation
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->reconciliation->requestFullPass();
    }
}
