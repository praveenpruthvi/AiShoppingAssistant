<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Attribute allowlisting for catalogue normalization.
 *
 * The policy fails closed: an attribute is excluded unless its code is a valid
 * lowercase attribute code AND is not on the internal/secret denylist. This
 * prevents admin notes, costs, and credentials from ever reaching the index.
 */
interface ProductAttributePolicyInterface
{
    /**
     * True when the attribute code may travel into a normalized document.
     */
    public function isAllowed(string $code): bool;

    /**
     * Filters a set of attributes to the allowed ones, dropping empty or
     * invalid ones. Returns a sorted list keyed by attribute code.
     *
     * @param array<string, SearchableAttributeInterface> $attributes keyed by code
     *
     * @return list<SearchableAttributeInterface> sorted by code
     */
    public function filter(array $attributes): array;
}
