<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * A single attribute that is allowed to travel into a normalized product document.
 */
interface SearchableAttributeInterface
{
    /**
     * Attribute code matching ^[a-z][a-z0-9_]{0,63}$.
     *
     * @throws CatalogException
     */
    public function code(): string;

    /**
     * Non-empty human-readable attribute label.
     *
     * @throws CatalogException
     */
    public function label(): string;

    /**
     * Non-empty list of non-empty normalized values.
     *
     * @return list<string>
     *
     * @throws CatalogException
     */
    public function values(): array;
}
