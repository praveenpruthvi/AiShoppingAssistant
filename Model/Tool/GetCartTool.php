<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * get_cart — read-only. Reading the cart is not itself a mutation, but it
 * is cart-adjacent, so it respects the same master
 * guardrails.cart_mutations_enabled toggle add_to_cart/remove_from_cart
 * do, per this task's explicit instruction — no separate capability toggle
 * and no confirmation gate for a read.
 *
 * Each line item's name/price is taken from live revalidation when the SKU
 * still passes it; a SKU that no longer does (disabled/out of stock/
 * deleted since being added) is still reported — using the cart's own
 * stored snapshot — rather than silently dropped, since it is genuinely in
 * the customer's cart right now and hiding it would look like a bug, not a
 * safety feature. `currently_available` distinguishes the two cases.
 */
final class GetCartTool implements CommerceToolInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CartResolverInterface $cartResolver,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly CartTotalRepositoryInterface $cartTotalRepository
    ) {
    }

    public function name(): string
    {
        return 'get_cart';
    }

    public function description(): string
    {
        return 'Get the current cart: line items (SKU, quantity, live price where available) and totals.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            // A plain [] here would json_encode() as a JSON array, not an
            // object — invalid for JSON Schema's `properties` keyword
            // (must be an object/map, even when empty). OpenAI's real API
            // tolerates this; a real, live-confirmed Ollama instance does
            // not — it rejects the entire chat request with HTTP 400
            // ("Value looks like object, but can't find closing '}'
            // symbol") the moment get_cart is offered as a tool. stdClass
            // always encodes as `{}`, matching every other tool's
            // non-empty `properties` map, which has no such issue.
            'properties' => new \stdClass(),
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readGuardrails($context->storeId)->areCartMutationsEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Cart access is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        try {
            $cart = $this->cartResolver->resolve($context->storeId, $context->cartId);
        } catch (CartNotAvailableException) {
            return new ToolResult(['status' => 'cart_not_available']);
        }

        $items = $cart->getItems() ?? [];
        $skus = array_values(array_unique(
            array_map(static fn (CartItemInterface $item): string => (string) $item->getSku(), $items)
        ));

        $verifiedBySku = [];
        foreach ($this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus) as $product) {
            $verifiedBySku[$product->sku] = $product;
        }

        $lines = [];
        $verifiedProducts = [];

        foreach ($items as $item) {
            $sku = (string) $item->getSku();
            $qty = (float) $item->getQty();

            if (isset($verifiedBySku[$sku])) {
                $product = $verifiedBySku[$sku];
                $verifiedProducts[] = $product;
                $lines[] = [
                    'sku' => $sku,
                    'qty' => $qty,
                    'currently_available' => true,
                    'name' => $product->name,
                    'price' => $product->price,
                    'special_price' => $product->specialPrice,
                ];

                continue;
            }

            $lines[] = [
                'sku' => $sku,
                'qty' => $qty,
                'currently_available' => false,
                'name' => (string) $item->getName(),
                'price' => (float) $item->getPrice(),
                'special_price' => null,
            ];
        }

        $totals = $this->cartTotalRepository->get((int) $cart->getId());

        return new ToolResult(
            [
                'status' => 'ok',
                'items' => $lines,
                'items_qty' => $totals->getItemsQty(),
                'subtotal' => $totals->getSubtotal(),
                'grand_total' => $totals->getGrandTotal(),
            ],
            $verifiedProducts
        );
    }
}
