<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use InvalidArgumentException;

/**
 * One product entry in the structured response contract.
 *
 * `reason` is the only field the LLM is trusted to supply — every other
 * fact (sku, price, url, verifiedAt) comes from the embedded
 * RevalidatedProduct, resolved from live Magento data, never from the LLM.
 * recommendationType is always "organic" today; the field exists so
 * Phase 2 merchandising (recommended/promoted) needs no shape change.
 */
final readonly class ProductResult
{
    public const TYPE_ORGANIC = 'organic';
    public const TYPE_RECOMMENDED = 'recommended';
    public const TYPE_PROMOTED = 'promoted';

    private const ALLOWED_TYPES = [self::TYPE_ORGANIC, self::TYPE_RECOMMENDED, self::TYPE_PROMOTED];

    public function __construct(
        public RevalidatedProduct $product,
        public string $reason,
        public string $recommendationType = self::TYPE_ORGANIC
    ) {
        if (!in_array($recommendationType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported recommendation type.');
        }
    }
}
