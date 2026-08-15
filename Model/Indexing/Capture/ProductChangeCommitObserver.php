<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

final class ProductChangeCommitObserver implements ObserverInterface
{
    public function __construct(
        private readonly ProductChangeScheduler $changeScheduler
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product) {
            throw new InvalidProductIndexEntityIdsException();
        }

        $this->changeScheduler->scheduleProductIfUpdateOnSave($product->getId());
    }
}
