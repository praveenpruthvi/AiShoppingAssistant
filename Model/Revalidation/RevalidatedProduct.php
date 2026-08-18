<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Revalidation;

use InvalidArgumentException;

/**
 * One product that has just been re-checked directly against live Magento
 * data: status, visibility, website assignment, stock/salability, and
 * customer-group-aware price. Mere presence in a revalidation result implies
 * the product passed every check — products that fail (not found, disabled,
 * not visible, not assigned to the website, not salable) are dropped by
 * LiveRevalidationService, never returned with a "failed" flag.
 *
 * price/specialPrice/url are the only source of truth for these facts
 * anywhere in the response pipeline — the LLM is never trusted to state
 * them itself. imageUrl follows the same discipline: always resolved
 * live from Magento's own catalog image APIs (LiveRevalidationService),
 * never LLM-sourced — but it's genuinely optional (a product can lack a
 * base image, or resolution can fail), so it's a nullable, defaulted
 * trailing parameter added after price/url were already established,
 * matching this class's constructor's existing shape for every prior
 * caller.
 */
final readonly class RevalidatedProduct
{
    public function __construct(
        public int $entityId,
        public string $sku,
        public string $name,
        public float $price,
        public ?float $specialPrice,
        public string $url,
        public string $verifiedAt,
        public ?string $imageUrl = null
    ) {
        if ($entityId < 1) {
            throw new InvalidArgumentException('A revalidated product requires a positive entity id.');
        }

        if ($sku === '') {
            throw new InvalidArgumentException('A revalidated product requires a non-empty SKU.');
        }

        if ($price < 0.0) {
            throw new InvalidArgumentException('A revalidated product price must not be negative.');
        }

        if ($specialPrice !== null && ($specialPrice < 0.0 || $specialPrice > $price)) {
            throw new InvalidArgumentException('A revalidated product special price must be between zero and the regular price.');
        }

        if ($url === '') {
            throw new InvalidArgumentException('A revalidated product requires a non-empty URL.');
        }

        if ($verifiedAt === '') {
            throw new InvalidArgumentException('A revalidated product requires a verification timestamp.');
        }

        if ($imageUrl === '') {
            throw new InvalidArgumentException('A revalidated product image URL, when provided, must not be empty.');
        }
    }
}
