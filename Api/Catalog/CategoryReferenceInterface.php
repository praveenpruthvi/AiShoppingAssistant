<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Immutable reference to a product category in a normalized document.
 */
interface CategoryReferenceInterface
{
    /**
     * Positive Magento category entity id.
     *
     * @throws CatalogException
     */
    public function categoryId(): int;

    /**
     * Non-empty category name.
     *
     * @throws CatalogException
     */
    public function name(): string;

    /**
     * Non-empty category path, e.g. "Root Catalog / Men / Shoes".
     *
     * @throws CatalogException
     */
    public function path(): string;
}
