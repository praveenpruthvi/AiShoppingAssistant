<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

/**
 * Shared, minimal argument parsing for the tools that take a `skus` list
 * (compare_products, check_price, check_inventory).
 */
final class SkuListParser
{
    /**
     * @return list<string>|null null when the raw value isn't a non-empty
     *     list of non-empty strings
     */
    public function parse(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $skus = [];
        foreach ($raw as $sku) {
            if (!is_string($sku) || trim($sku) === '') {
                return null;
            }

            $skus[] = $sku;
        }

        return $skus;
    }
}
