<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * check_price — live, customer-group-aware price for one or more SKUs,
 * reusing LiveRevalidationService. A SKU that isn't currently purchasable
 * has no meaningful price to report, so (unlike check_inventory) this
 * tool is fine reusing revalidate()'s drop-on-failure behavior directly —
 * it is simply absent from the result, same as compare_products'
 * not_found list communicates.
 */
final class CheckPriceTool implements CommerceToolInterface
{
    private const MAX_SKUS = 10;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly SkuListParser $skuListParser
    ) {
    }

    public function name(): string
    {
        return 'check_price';
    }

    public function description(): string
    {
        return 'Get the current, customer-group-aware price for one or more exact SKUs (up to '
            . self::MAX_SKUS . '). A SKU not currently purchasable is reported in not_found.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'The exact SKUs to price.',
                ],
            ],
            'required' => ['skus'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isPriceCheckingEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Price checking is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $skus = $this->skuListParser->parse($arguments['skus'] ?? null);

        if ($skus === null) {
            return new ToolResult(['error' => 'A non-empty list of string skus is required.']);
        }

        if (count($skus) > self::MAX_SKUS) {
            return new ToolResult(['error' => 'At most ' . self::MAX_SKUS . ' skus may be priced at once.']);
        }

        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);

        $prices = array_map(
            static fn ($product): array => [
                'sku' => $product->sku,
                'price' => $product->price,
                'special_price' => $product->specialPrice,
            ],
            $verified
        );

        $foundSkus = array_map(static fn ($product): string => $product->sku, $verified);
        $notFound = array_values(array_diff($skus, $foundSkus));

        return new ToolResult(['prices' => $prices, 'not_found' => $notFound], $verified);
    }
}
