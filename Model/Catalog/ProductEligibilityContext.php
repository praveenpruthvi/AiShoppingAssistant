<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityContextInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class ProductEligibilityContext implements ProductEligibilityContextInterface
{
    public function __construct(
        private int $storeId,
        private int $websiteId
    ) {
        if ($storeId < 1 || $websiteId < 1) {
            throw new CatalogException(__('Store and website ids must be positive integers.'));
        }
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function websiteId(): int
    {
        return $this->websiteId;
    }
}
