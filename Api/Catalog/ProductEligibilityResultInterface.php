<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Immutable decision of the product index eligibility policy.
 */
interface ProductEligibilityResultInterface
{
    /** Product is eligible and may be normalized. */
    public const REASON_ELIGIBLE = 'eligible';

    /** Snapshot identity (entity id, sku, store id, product type) is invalid. */
    public const REASON_INVALID_IDENTITY = 'invalid_identity';

    /** The snapshot does not belong to the requested store view. */
    public const REASON_STORE_MISMATCH = 'store_mismatch';

    /** The product is not assigned to the requested website. */
    public const REASON_WEBSITE_NOT_ASSIGNED = 'website_not_assigned';

    /** The product status attribute is disabled. */
    public const REASON_DISABLED = 'disabled';

    /** The product is not visible in search or catalog+search scope. */
    public const REASON_NOT_SEARCH_VISIBLE = 'not_search_visible';

    /**
     * True when the product is eligible for the requested scope.
     */
    public function eligible(): bool;

    /**
     * One of the REASON_* constants.
     *
     * @throws CatalogException
     */
    public function reasonCode(): string;
}
