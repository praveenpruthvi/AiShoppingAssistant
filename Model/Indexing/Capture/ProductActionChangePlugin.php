<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Magento\Catalog\Model\Product\Action;

final class ProductActionChangePlugin
{
    public function __construct(
        private readonly ProductChangeScheduler $changeScheduler
    ) {
    }

    /**
     * @param array<mixed> $productIds
     * @param array<mixed> $attrData
     */
    public function afterUpdateAttributes(
        Action $subject,
        Action $result,
        array $productIds,
        array $attrData,
        mixed $storeId
    ): Action {
        $this->changeScheduler->scheduleProductsIfUpdateOnSave($productIds);

        return $result;
    }

    /**
     * @param array<mixed> $productIds
     * @param array<mixed> $websiteIds
     */
    public function afterUpdateWebsites(
        Action $subject,
        mixed $result,
        array $productIds,
        array $websiteIds,
        string $type
    ): mixed {
        $this->changeScheduler->scheduleProductsIfUpdateOnSave($productIds);

        return $result;
    }
}
