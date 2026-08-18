<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Shared shape for turning a live-revalidated product into the JSON fed
 * back to the model as a tool result. Every field comes from
 * RevalidatedProduct — never anything the model itself supplied.
 */
final class ProductFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(RevalidatedProduct $product): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => $product->price,
            'special_price' => $product->specialPrice,
            'url' => $product->url,
        ];
    }
}
