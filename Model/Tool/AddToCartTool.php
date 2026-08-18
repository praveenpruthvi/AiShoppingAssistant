<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Api\Data\ConfigurableItemOptionValueInterfaceFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\Data\ProductOptionExtensionFactory;
use Magento\Quote\Api\Data\ProductOptionInterface;
use Magento\Quote\Api\Data\ProductOptionInterfaceFactory;
use Psr\Log\LoggerInterface;

/**
 * add_to_cart — the first WRITE tool in this module. Gated by
 * guardrails.cart_mutations_enabled (offered at all) and, when
 * guardrails.require_cart_confirmation is on, by a server-verified
 * confirmation token (see CartMutationConfirmationService) — the model can
 * never cause an add by asserting confirmation in its own text.
 *
 * Always revalidates the SKU via LiveRevalidationServiceInterface
 * immediately before adding (reusing Task 6's exact path, never a second
 * stock/salability check) — an item that fails is rejected before any
 * confirmation is even proposed, since there is nothing to confirm.
 *
 * Uses CartItemRepositoryInterface::save() (Magento's own public cart-item
 * API — the same mechanism the REST `POST /V1/carts/mine/items` endpoint
 * uses internally) rather than manipulating a Quote model directly; this
 * also means an existing line for the same SKU is merged/qty-updated by
 * Magento's own item processor, not duplicated.
 *
 * Configurable products (size/color/etc. selection required): $sku always
 * stays the parent configurable SKU throughout, matching how Magento's own
 * cart item API expects a configurable add — the parent is what
 * LiveRevalidationServiceInterface (and the store's catalogue generally)
 * treats as visible/salable, never the individually-not-visible child. A
 * call with no `option_selection` (or one that doesn't fully resolve to
 * exactly one real child, salable in its own right) never mutates the
 * cart; it returns a `needs_options`/`invalid_option`/`not_purchasable`
 * result identifying exactly what's still needed or wrong, so a
 * fail-closed non-answer is never silently treated as "add anything."
 */
final class AddToCartTool implements CommerceToolInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CartResolverInterface $cartResolver,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly CartMutationConfirmationService $confirmationService,
        private readonly CartItemInterfaceFactory $cartItemFactory,
        private readonly CartItemRepositoryInterface $cartItemRepository,
        private readonly ProductFormatter $productFormatter,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Configurable $configurableType,
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ProductOptionInterfaceFactory $productOptionFactory,
        private readonly ProductOptionExtensionFactory $productOptionExtensionFactory,
        private readonly ConfigurableItemOptionValueInterfaceFactory $configurableItemOptionValueFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function name(): string
    {
        return 'add_to_cart';
    }

    public function description(): string
    {
        return 'Add a quantity of one exact SKU to the current cart. The first call for a given SKU/quantity may '
            . 'return confirmation_required instead of adding it — relay that proposal to the customer verbatim, '
            . 'wait for their explicit yes, then call again with the same sku/qty and the confirmation_token you '
            . 'were given. Never claim the customer confirmed without actually receiving a confirmation_token back '
            . 'from a prior call. If the product needs a size/color/etc. selection, the result has status '
            . 'needs_options listing what to ask the customer for — call again with option_selection set to their '
            . 'answer in their own words (e.g. "XL, pink").';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The exact SKU to add.'],
                'qty' => ['type' => 'integer', 'minimum' => 1, 'description' => 'How many to add.'],
                'option_selection' => [
                    'type' => 'string',
                    'description' => 'For a product that needs a size/color/etc. selection: the customer\'s '
                        . 'stated choice, in their own words (e.g. "XL, pink"). Omit on the first call for such a '
                        . 'product — the result will list what\'s required.',
                ],
                'confirmation_token' => [
                    'type' => 'string',
                    'description' => 'Only set this to the exact confirmation_token a prior call to this same '
                        . 'tool returned, after the customer has explicitly confirmed.',
                ],
            ],
            'required' => ['sku', 'qty'],
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
        $qty = $arguments['qty'] ?? null;
        $optionSelectionText = $arguments['option_selection'] ?? null;
        $optionSelectionText = is_string($optionSelectionText) && trim($optionSelectionText) !== ''
            ? trim($optionSelectionText)
            : null;

        if (!is_string($sku) || trim($sku) === '') {
            return new ToolResult(['status' => 'rejected', 'reason' => 'invalid_arguments']);
        }

        if (!is_int($qty) || $qty < 1) {
            return new ToolResult(['status' => 'rejected', 'reason' => 'invalid_arguments']);
        }

        try {
            $cart = $this->cartResolver->resolve($context->storeId, $context->cartId);
        } catch (CartNotAvailableException) {
            return new ToolResult(['status' => 'cart_not_available']);
        }

        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, [$sku]);

        if ($verified === []) {
            return new ToolResult(['status' => 'rejected', 'reason' => 'not_purchasable', 'sku' => $sku]);
        }

        try {
            $product = $this->productRepository->get($sku, false, $context->storeId, true);
        } catch (NoSuchEntityException) {
            return new ToolResult(['status' => 'rejected', 'reason' => 'not_purchasable', 'sku' => $sku]);
        }

        $configurableOptions = null;

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $selectionOutcome = $this->resolveConfigurableSelection($product, $context->storeId, $optionSelectionText);

            if ($selectionOutcome['status'] !== 'resolved') {
                return new ToolResult($selectionOutcome);
            }

            $configurableOptions = $selectionOutcome['options'];
        }

        $guardrails = $this->configurationReader->readGuardrails($context->storeId);
        $proposal = [
            'action' => 'add_to_cart',
            'cart_id' => $context->cartId,
            'sku' => $sku,
            'qty' => $qty,
            'option_selection' => $optionSelectionText,
        ];

        if ($guardrails->requiresCartConfirmation()) {
            $token = $arguments['confirmation_token'] ?? null;
            $confirmed = is_string($token) && $token !== ''
                && $this->confirmationService->redeem($token, $context->turnId, $proposal);

            if (!$confirmed) {
                return new ToolResult($this->confirmationRequired($context, $proposal, $sku, $qty));
            }
        }

        try {
            $item = $this->cartItemFactory->create();
            $item->setQuoteId((int) $cart->getId());
            $item->setSku($sku);
            $item->setQty($qty);

            if ($configurableOptions !== null) {
                $item->setProductOption($this->buildProductOption($configurableOptions));
            }

            $this->cartItemRepository->save($item);
        } catch (\Throwable $throwable) {
            $this->logger->error('AI shopping assistant: add_to_cart failed.', [
                'store_id' => $context->storeId,
                'cart_id' => $context->cartId,
                'sku' => $sku,
                'qty' => $qty,
                'exception' => $throwable->getMessage(),
            ]);

            return new ToolResult(['status' => 'failed', 'reason' => 'cart_update_failed', 'sku' => $sku]);
        }

        $this->logger->info('AI shopping assistant: added item to cart.', [
            'store_id' => $context->storeId,
            'cart_id' => $context->cartId,
            'sku' => $sku,
            'qty' => $qty,
        ]);

        return new ToolResult(
            [
                'status' => 'added',
                'sku' => $sku,
                'qty' => $qty,
                'product' => $this->productFormatter->format($verified[0]),
            ],
            $verified
        );
    }

    /**
     * Matches $optionSelectionText's comma-separated phrases against the
     * configurable product's own attribute/value labels (case-insensitive,
     * substring-tolerant — "pink one" matches "Pink"), and — only once
     * every required attribute resolved to exactly one value AND that
     * combination corresponds to exactly one real, salable child — returns
     * the {attribute_id: value_index} map Magento's cart item API expects.
     * Every other outcome (nothing given yet, an unrecognized phrase, a
     * conflicting/incomplete/ambiguous selection, a combination with no
     * matching child, a matching child that isn't currently salable)
     * returns a distinct, honest status and never guesses a value.
     *
     * @return array{status: string, sku?: string, options?: array<int, int|string>, invalid_value?: string, option_types?: list<array{attribute: string, values: list<string>}>}
     */
    private function resolveConfigurableSelection(Product $product, int $storeId, ?string $optionSelectionText): array
    {
        $sku = (string) $product->getSku();
        $attributes = $this->configurableType->getConfigurableAttributesAsArray($product);

        if ($attributes === []) {
            // Configurable in name only (misconfigured, no attributes) —
            // nothing to select, so treat it like a simple add.
            return ['status' => 'resolved', 'options' => []];
        }

        $optionTypes = $this->describeOptionTypes($attributes);

        if ($optionSelectionText === null) {
            return ['status' => 'needs_options', 'sku' => $sku, 'option_types' => $optionTypes];
        }

        $resolvedValueIndexes = [];
        foreach (array_map('trim', explode(',', $optionSelectionText)) as $phrase) {
            if ($phrase === '') {
                continue;
            }

            $match = $this->matchPhraseToValue($phrase, $attributes);

            if ($match === null) {
                return ['status' => 'rejected', 'reason' => 'invalid_option', 'invalid_value' => $phrase];
            }

            [$attributeId, $valueIndex] = $match;

            if (isset($resolvedValueIndexes[$attributeId]) && $resolvedValueIndexes[$attributeId] !== $valueIndex) {
                return ['status' => 'rejected', 'reason' => 'invalid_option', 'invalid_value' => $phrase];
            }

            $resolvedValueIndexes[$attributeId] = $valueIndex;
        }

        if (count($resolvedValueIndexes) < count($attributes)) {
            return ['status' => 'needs_options', 'sku' => $sku, 'option_types' => $optionTypes];
        }

        $attributeCodesById = array_map(
            static fn (array $attribute): string => (string) $attribute['attribute_code'],
            $attributes
        );
        $child = $this->findMatchingChild($product, $resolvedValueIndexes, $attributeCodesById);

        if ($child === null) {
            return ['status' => 'rejected', 'reason' => 'invalid_option', 'invalid_value' => $optionSelectionText];
        }

        if (!$this->isChildPurchasable($child, $storeId)) {
            return ['status' => 'rejected', 'reason' => 'not_purchasable', 'sku' => (string) $child->getSku()];
        }

        return ['status' => 'resolved', 'options' => $resolvedValueIndexes];
    }

    /**
     * @param array<int, array{attribute_code: string, label: string, frontend_label: string, values: array<int, array{value_index: int|string, label: string}>}> $attributes
     *
     * @return list<array{attribute: string, values: list<string>}>
     */
    private function describeOptionTypes(array $attributes): array
    {
        $types = [];

        foreach ($attributes as $attribute) {
            $label = $attribute['label'] !== '' ? $attribute['label'] : $attribute['frontend_label'];
            $types[] = [
                'attribute' => (string) $label,
                'values' => array_map(
                    static fn (array $value): string => (string) $value['label'],
                    $attribute['values']
                ),
            ];
        }

        return $types;
    }

    /**
     * @param array<int, array{values: array<int, array{value_index: int|string, label: string}>}> $attributes
     *
     * @return array{0: int, 1: int|string}|null
     */
    private function matchPhraseToValue(string $phrase, array $attributes): ?array
    {
        $normalizedPhrase = mb_strtolower($phrase);
        $matches = [];

        foreach ($attributes as $attributeId => $attribute) {
            foreach ($attribute['values'] as $value) {
                $normalizedLabel = mb_strtolower((string) $value['label']);

                if ($normalizedLabel === '') {
                    continue;
                }

                if ($normalizedPhrase === $normalizedLabel || str_contains($normalizedPhrase, $normalizedLabel)) {
                    $matches[] = [(int) $attributeId, $value['value_index']];
                }
            }
        }

        // More than one distinct (attribute, value) pair matched the same
        // phrase — whether across different attributes or two values of
        // the same one — is genuinely ambiguous; never guess which one the
        // customer meant.
        $distinctMatches = array_unique(
            array_map(static fn (array $match): string => $match[0] . ':' . $match[1], $matches)
        );

        if (count($distinctMatches) > 1) {
            return null;
        }

        return $matches[0] ?? null;
    }

    /**
     * @param array<int, int|string> $valueIndexesByAttributeId
     * @param array<int, string> $attributeCodesById
     */
    private function findMatchingChild(Product $product, array $valueIndexesByAttributeId, array $attributeCodesById): ?Product
    {
        foreach ($this->configurableType->getUsedProducts($product) as $child) {
            $isMatch = true;

            foreach ($valueIndexesByAttributeId as $attributeId => $valueIndex) {
                $attributeCode = $attributeCodesById[$attributeId] ?? null;

                if ($attributeCode === null || (string) $child->getData($attributeCode) !== (string) $valueIndex) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Deliberately bypasses LiveRevalidationServiceInterface for this one
     * check: a configurable child is legitimately not individually visible
     * in the catalogue (it is sold *as* the parent with this selection),
     * so LiveRevalidationService::isAvailable()'s visibility gate would
     * incorrectly reject a real, purchasable combination. Stock/salability
     * still apply to the specific child, since the parent being salable
     * only guarantees *some* combination is — not necessarily this one.
     */
    private function isChildPurchasable(Product $child, int $storeId): bool
    {
        $scope = $this->storeScopeProvider->requireActive($storeId);
        $stockItem = $this->stockRegistry->getStockItem((int) $child->getId(), $scope->websiteId());

        return $stockItem->getIsInStock() && $child->isSalable();
    }

    /**
     * @param array<int, int|string> $valueIndexesByAttributeId
     */
    private function buildProductOption(array $valueIndexesByAttributeId): ProductOptionInterface
    {
        $configurableItemOptions = [];

        foreach ($valueIndexesByAttributeId as $attributeId => $valueIndex) {
            $option = $this->configurableItemOptionValueFactory->create();
            $option->setOptionId($attributeId);
            $option->setOptionValue((int) $valueIndex);
            $configurableItemOptions[] = $option;
        }

        $extensionAttributes = $this->productOptionExtensionFactory->create();
        $extensionAttributes->setConfigurableItemOptions($configurableItemOptions);

        $productOption = $this->productOptionFactory->create();
        $productOption->setExtensionAttributes($extensionAttributes);

        return $productOption;
    }

    /**
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    private function confirmationRequired(ToolContext $context, array $proposal, string $sku, int $qty): array
    {
        $token = $this->confirmationService->createToken($context->turnId, $proposal);

        return [
            'status' => 'confirmation_required',
            'action' => 'add_to_cart',
            'sku' => $sku,
            'qty' => $qty,
            'confirmation_token' => $token,
            'message' => 'Ask the customer to explicitly confirm adding ' . $qty . ' of ' . $sku
                . ' to their cart, then call add_to_cart again with the same sku and qty plus this confirmation_token.',
        ];
    }
}
