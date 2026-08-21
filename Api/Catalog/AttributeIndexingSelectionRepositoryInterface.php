<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * The one place that reads/writes which product attributes a merchant has
 * selected to feed the AI Shopping Assistant's index — the single shared
 * source of truth both admin entry points (the native Stores > Attributes
 * > Product grid column/mass action, and this module's own bulk-select
 * screen) read and write through, and the same source the indexing
 * pipeline reads at reindex time. Global/catalog-wide, not store-scoped
 * (mirrors MerchandisingBoost's own deliberate no-store_id precedent) —
 * an attribute is selected for the assistant index or it isn't, the same
 * way across every store view.
 */
interface AttributeIndexingSelectionRepositoryInterface
{
    /**
     * Every attribute code this repository has an explicit row for, and
     * its current selection state. A code with no row at all is neither
     * present here nor implicitly true/false — callers that need a
     * default for an unselected code should treat "absent" as false.
     *
     * @return array<string, bool> attribute_code => is_indexed
     */
    public function all(): array;

    /**
     * Only the codes currently selected for indexing, in the indexing
     * pipeline's own consumption shape.
     *
     * @return list<string>
     */
    public function indexedCodes(): array;

    public function isIndexed(string $attributeCode): bool;

    /**
     * Sets exactly these codes' selection state, leaving every other
     * code's existing row untouched — the shape both the native grid's
     * mass action (a handful of codes at a time) and the bulk-select
     * screen (every submitted checkbox, checked or not, in one call)
     * need.
     *
     * @param list<string> $attributeCodes
     */
    public function setIndexed(array $attributeCodes, bool $isIndexed): void;
}
