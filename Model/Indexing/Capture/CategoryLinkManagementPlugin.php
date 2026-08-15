<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture;

use Magento\Catalog\Model\CategoryLinkManagement;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

final class CategoryLinkManagementPlugin
{
    public function __construct(
        private readonly ProductChangeScheduler $changeScheduler,
        private readonly ProductResource $productResource
    ) {
    }

    /**
     * @param list<int|string> $categoryIds
     */
    public function afterAssignProductToCategories(
        CategoryLinkManagement $subject,
        bool $result,
        string $productSku,
        array $categoryIds
    ): bool {
        $this->changeScheduler->scheduleProductIfUpdateOnSave($this->productResource->getIdBySku($productSku));

        return $result;
    }
}
