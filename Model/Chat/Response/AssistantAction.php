<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

use InvalidArgumentException;

/**
 * One suggested follow-up action referencing already-verified SKUs, e.g.
 * {"type": "compare", "skus": ["SKU-1", "SKU-2"]}.
 */
final readonly class AssistantAction
{
    /**
     * @param list<string> $skus
     */
    public function __construct(
        public string $type,
        public array $skus
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('An assistant action requires a non-empty type.');
        }

        foreach ($skus as $sku) {
            if (!is_string($sku) || $sku === '') {
                throw new InvalidArgumentException('An assistant action SKU must be a non-empty string.');
            }
        }
    }
}
