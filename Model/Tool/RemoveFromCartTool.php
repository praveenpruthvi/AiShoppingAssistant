<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Psr\Log\LoggerInterface;

/**
 * remove_from_cart — the second WRITE tool. Same confirmation-gate
 * mechanism as add_to_cart (see CartMutationConfirmationService), applied
 * only once the SKU is confirmed to actually be in the cart: removing
 * something already absent is a no-op that needs no confirmation, per this
 * task's explicit instruction. Presence is re-checked again immediately
 * before the actual deletion (not just at proposal time), since cart state
 * could change between the two calls.
 */
final class RemoveFromCartTool implements CommerceToolInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CartResolverInterface $cartResolver,
        private readonly CartMutationConfirmationService $confirmationService,
        private readonly CartItemRepositoryInterface $cartItemRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function name(): string
    {
        return 'remove_from_cart';
    }

    public function description(): string
    {
        return 'Remove one exact SKU entirely from the current cart. A SKU not currently in the cart is reported '
            . 'as not_in_cart, not an error. The first call for a SKU that IS present may return '
            . 'confirmation_required instead of removing it — relay that proposal to the customer verbatim, wait '
            . 'for their explicit yes, then call again with the same sku and the confirmation_token you were given.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The exact SKU to remove.'],
                'confirmation_token' => [
                    'type' => 'string',
                    'description' => 'Only set this to the exact confirmation_token a prior call to this same '
                        . 'tool returned, after the customer has explicitly confirmed.',
                ],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readGuardrails($context->storeId)->areCartMutationsEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Cart changes are disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $sku = $arguments['sku'] ?? null;

        if (!is_string($sku) || trim($sku) === '') {
            return new ToolResult(['status' => 'rejected', 'reason' => 'invalid_arguments']);
        }

        try {
            $cart = $this->cartResolver->resolve($context->storeId, $context->cartId);
        } catch (CartNotAvailableException) {
            return new ToolResult(['status' => 'cart_not_available']);
        }

        if ($this->findItem($cart, $sku) === null) {
            return new ToolResult(['status' => 'not_in_cart', 'sku' => $sku]);
        }

        $guardrails = $this->configurationReader->readGuardrails($context->storeId);
        $proposal = ['action' => 'remove_from_cart', 'cart_id' => $context->cartId, 'sku' => $sku];

        if ($guardrails->requiresCartConfirmation()) {
            $token = $arguments['confirmation_token'] ?? null;
            $confirmed = is_string($token) && $token !== ''
                && $this->confirmationService->redeem($token, $context->turnId, $proposal);

            if (!$confirmed) {
                return new ToolResult($this->confirmationRequired($context, $proposal, $sku));
            }
        }

        // Re-check presence right before deleting: state may have changed
        // between the proposal and this confirmed call.
        $item = $this->findItem($cart, $sku);

        if ($item === null) {
            return new ToolResult(['status' => 'not_in_cart', 'sku' => $sku]);
        }

        try {
            $this->cartItemRepository->deleteById((int) $cart->getId(), (int) $item->getItemId());
        } catch (\Throwable $throwable) {
            $this->logger->error('AI shopping assistant: remove_from_cart failed.', [
                'store_id' => $context->storeId,
                'cart_id' => $context->cartId,
                'sku' => $sku,
                'exception' => $throwable->getMessage(),
            ]);

            return new ToolResult(['status' => 'failed', 'reason' => 'cart_update_failed', 'sku' => $sku]);
        }

        $this->logger->info('AI shopping assistant: removed item from cart.', [
            'store_id' => $context->storeId,
            'cart_id' => $context->cartId,
            'sku' => $sku,
        ]);

        return new ToolResult(['status' => 'removed', 'sku' => $sku]);
    }

    private function findItem(CartInterface $cart, string $sku): ?CartItemInterface
    {
        foreach ($cart->getItems() ?? [] as $item) {
            if ($item->getSku() === $sku) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    private function confirmationRequired(ToolContext $context, array $proposal, string $sku): array
    {
        $token = $this->confirmationService->createToken($context->turnId, $proposal);

        return [
            'status' => 'confirmation_required',
            'action' => 'remove_from_cart',
            'sku' => $sku,
            'confirmation_token' => $token,
            'message' => 'Ask the customer to explicitly confirm removing ' . $sku
                . ' from their cart, then call remove_from_cart again with the same sku plus this confirmation_token.',
        ];
    }
}
