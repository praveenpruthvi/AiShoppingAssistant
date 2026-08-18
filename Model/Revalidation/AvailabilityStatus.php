<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Revalidation;

use InvalidArgumentException;

/**
 * Per-SKU availability outcome for LiveRevalidationServiceInterface::checkAvailability().
 *
 * Unlike revalidate() (which silently drops anything that fails), this
 * carries one entry per *requested* SKU so a caller can positively state
 * "out of stock" rather than that SKU simply being absent — that
 * distinction is the entire point of a stock-check tool.
 *
 * `inStock` deliberately collapses "genuinely out of stock" and "exists
 * but currently hidden/disabled/not salable" into one "not currently
 * available" signal rather than exposing Magento's exact internal reason
 * — that level of detail isn't customer-relevant for this tool's purpose,
 * and finer-grained reasons risk leaking internal store state.
 */
final readonly class AvailabilityStatus
{
    public function __construct(
        public string $sku,
        public bool $found,
        public bool $inStock,
        public ?string $name
    ) {
        if ($sku === '') {
            throw new InvalidArgumentException('An availability status requires a non-empty SKU.');
        }

        if (!$found && $inStock) {
            throw new InvalidArgumentException('An availability status cannot be in stock without being found.');
        }
    }
}
