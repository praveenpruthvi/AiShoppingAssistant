<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

final class CategoryChangeCommitObserver implements ObserverInterface
{
    public function __construct(
        private readonly ProductChangeScheduler $changeScheduler,
        private readonly IncrementalProductReconciliationInterface $reconciliation
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if ($category instanceof Category) {
            $ids = $category->getAffectedProductIds();
            if (is_array($ids) && $ids !== []) {
                $this->changeScheduler->scheduleProductsIfUpdateOnSave($ids);
            }
        }

        $this->reconciliation->requestFullPass();
    }
}
