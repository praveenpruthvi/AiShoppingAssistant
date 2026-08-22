<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;

/**
 * The single read/write path for category boost rows — both the category
 * edit form's own boost field (Task 33) and the standalone category boost
 * grid's inline edit/delete actions go through this one interface,
 * mirroring MerchandisingBoostRepositoryInterface's own reasoning exactly
 * (see that interface's docblock for why this is deliberately the single
 * such path, and why it's kept separate from ActiveCategoryBoostReaderInterface).
 */
interface CategoryBoostRepositoryInterface
{
    /**
     * Creates a new boost (when boostId() is null) or updates an existing
     * one, returning the persisted row (with a real boostId() and
     * created_at/updated_at populated).
     *
     * @throws MerchandisingBoostException when the boost's own field
     *     values are invalid (negative weight, end before start, etc.)
     */
    public function save(CategoryBoostInterface $boost): CategoryBoostInterface;

    /**
     * @throws MerchandisingBoostException when no boost with this id exists
     */
    public function getById(int $boostId): CategoryBoostInterface;

    /**
     * Unlike MerchandisingBoostRepositoryInterface, which has no
     * find-by-product-id lookup (the product-grid mass-action flow always
     * creates a new boost row, never upserts), this repository needs one:
     * the category edit form's own boost field (Task 33) is a field ON
     * the category's own entity, not a "go create a new boost" link, so
     * saving it must UPDATE an already-existing boost for that category
     * rather than accumulating duplicate rows every time the category is
     * saved. A category may have at most one boost row in practice
     * (enforced by this repository's own save() path for that entry
     * point, though the schema itself does not forbid more), so this
     * returns at most one — the first active-or-not row found, preferring
     * the most recently updated if somehow more than one exists.
     */
    public function findByCategoryId(int $categoryId): ?CategoryBoostInterface;

    /**
     * Idempotent — deleting an id that no longer exists is not an error.
     */
    public function deleteById(int $boostId): void;
}
