<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;

/**
 * The single read/write path for merchandising boost rows — both the
 * product-grid mass-action save flow and the standalone boost grid's
 * inline edit/delete actions go through this one interface, so there is
 * exactly one place that validates and persists a boost, never two
 * divergent implementations of the same save/delete logic.
 *
 * Deliberately separate from ActiveBoostReaderInterface: this interface
 * serves admin CRUD (occasional, one row at a time, needs full validation
 * and the up-to-date row back); the reader serves the ranking pipeline's
 * hot path (every chat turn, many products at once, needs to be fast and
 * side-effect-free). Splitting them keeps neither concern compromised by
 * the other's requirements.
 */
interface MerchandisingBoostRepositoryInterface
{
    /**
     * Creates a new boost (when boostId() is null) or updates an existing
     * one, returning the persisted row (with a real boostId() and
     * created_at/updated_at populated).
     *
     * @throws MerchandisingBoostException when the boost's own field
     *     values are invalid (negative weight, end before start, etc.)
     */
    public function save(MerchandisingBoostInterface $boost): MerchandisingBoostInterface;

    /**
     * @throws MerchandisingBoostException when no boost with this id exists
     */
    public function getById(int $boostId): MerchandisingBoostInterface;

    /**
     * Idempotent — deleting an id that no longer exists is not an error.
     */
    public function deleteById(int $boostId): void;
}
