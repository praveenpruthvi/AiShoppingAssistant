<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Revalidation;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Store-scoped, customer-group-aware live revalidation against Magento
 * itself — status, visibility, website assignment, stock/salability, and
 * price. This is the only place in the runtime pipeline allowed to state a
 * price, URL, or availability fact; the assistant index and the LLM are
 * never trusted for any of these.
 *
 * A null customerGroupId means the caller has no known customer context
 * (no Controller/session layer wires a real one through yet); it resolves
 * to Magento's NOT_LOGGED_IN group.
 */
interface LiveRevalidationServiceInterface
{
    /**
     * @param list<string> $skus
     *
     * @return list<RevalidatedProduct> only SKUs that passed every check;
     *     failures are silently dropped, never returned with a failed flag
     */
    public function revalidate(int $storeId, ?int $customerGroupId, array $skus): array;

    /**
     * Reports a status for every *requested* SKU, unlike revalidate() —
     * for a stock-check use case, "out of stock" is a valid, positive
     * answer that must not be indistinguishable from "not found."
     *
     * @param list<string> $skus
     *
     * @return list<AvailabilityStatus> exactly one entry per unique requested SKU
     */
    public function checkAvailability(int $storeId, ?int $customerGroupId, array $skus): array;
}
