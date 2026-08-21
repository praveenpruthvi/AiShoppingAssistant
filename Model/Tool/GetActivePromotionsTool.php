<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ActivePromotionReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * get_active_promotions — real, currently-active discounts: Catalog Price
 * Rules for specific SKUs (when given) plus every active Cart Price Rule
 * for this store's website/customer-group scope, regardless of SKUs. The
 * model calls this when a shopper asks about deals/discounts/sales/coupon
 * codes; ChatEntryPipeline (see PromotionContextFormatter) also resolves
 * catalog-rule discounts proactively for this turn's already-ranked
 * candidates, so a genuinely discounted product gets mentioned even when
 * the shopper never explicitly asks — this tool exists for the cases that
 * proactive path doesn't cover (a SKU not in this turn's candidates, or a
 * general "what deals do you have" with no product in mind at all).
 *
 * Products passed via `skus` go through LiveRevalidationServiceInterface
 * first, the same live-data discipline check_price/check_inventory
 * already use, so a discount is only ever reported against a real,
 * currently-purchasable product's real regular price.
 */
final class GetActivePromotionsTool implements CommerceToolInterface
{
    private const MAX_SKUS = 10;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly ActivePromotionReaderInterface $promotionReader,
        private readonly SkuListParser $skuListParser
    ) {
    }

    public function name(): string
    {
        return 'get_active_promotions';
    }

    public function description(): string
    {
        return 'Get real, currently-active discounts: optionally pass up to ' . self::MAX_SKUS
            . ' exact SKUs to check for an active Catalog Price Rule discount on those specific '
            . 'products, and always get every currently-active Cart Price Rule for this store '
            . '(each one flagged as automatic or requiring a coupon code). Use this whenever a '
            . 'customer asks about deals, discounts, sales, or coupon codes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional exact SKUs to check for an active catalog price rule discount.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isPromotionAwarenessEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Promotion awareness is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $rawSkus = $arguments['skus'] ?? null;
        $skus = $rawSkus === null ? [] : $this->skuListParser->parse($rawSkus);

        if ($skus === null) {
            return new ToolResult(['error' => 'skus, when given, must be a non-empty list of strings.']);
        }

        if (count($skus) > self::MAX_SKUS) {
            return new ToolResult(['error' => 'At most ' . self::MAX_SKUS . ' skus may be checked at once.']);
        }

        $verified = $skus === []
            ? []
            : $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);

        $catalogDiscounts = $this->promotionReader->catalogRuleDiscounts(
            $context->storeId,
            $context->customerGroupId,
            $verified
        );
        $cartRules = $this->promotionReader->activeCartRules($context->storeId, $context->customerGroupId);

        $foundSkus = array_map(static fn ($product): string => $product->sku, $verified);
        $notFound = array_values(array_diff($skus, $foundSkus));

        return new ToolResult(
            [
                'product_discounts' => array_map($this->serializeProductPromotion(...), array_values($catalogDiscounts)),
                'cart_rules' => array_map($this->serializeCartPromotion(...), $cartRules),
                'not_found' => $notFound,
            ],
            $verified,
            array_values($catalogDiscounts),
            $cartRules
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProductPromotion(ProductPromotionInterface $promotion): array
    {
        return [
            'sku' => $promotion->sku(),
            'regular_price' => $promotion->regularPrice(),
            'discounted_price' => $promotion->discountedPrice(),
            'percent_off' => $promotion->percentOff(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCartPromotion(CartPromotionInterface $promotion): array
    {
        return [
            'name' => $promotion->name(),
            'requires_coupon' => $promotion->requiresCoupon(),
            'coupon_code' => $promotion->couponCode(),
            'discount_description' => $promotion->discountDescription(),
            'to_date' => $promotion->toDate(),
        ];
    }
}
