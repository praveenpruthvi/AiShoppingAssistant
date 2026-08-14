<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Store/website scope for which a product eligibility decision is requested.
 */
interface ProductEligibilityContextInterface
{
    /**
     * Positive Magento store view id.
     *
     * @throws CatalogException
     */
    public function storeId(): int;

    /**
     * Positive Magento website id the store view belongs to.
     *
     * @throws CatalogException
     */
    public function websiteId(): int;
}
