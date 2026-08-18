<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * compare_products — several SKUs -> live-revalidated details for each,
 * side by side. Reuses LiveRevalidationService for every SKU in one call.
 */
final class CompareProductsTool implements CommerceToolInterface
{
    /**
     * Small, fixed cap on how many SKUs one comparison call may request —
     * protects against a runaway request; a genuine product comparison
     * rarely needs more than a handful of items at once.
     */
    private const MAX_SKUS = 5;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly ProductFormatter $productFormatter,
        private readonly SkuListParser $skuListParser
    ) {
    }

    public function name(): string
    {
        return 'compare_products';
    }

    public function description(): string
    {
        return 'Compare up to ' . self::MAX_SKUS . ' exact SKUs side by side using live, verified data. '
            . 'Any SKU that does not exist or is not currently available is reported separately, not silently dropped.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'The exact SKUs to compare.',
                ],
            ],
            'required' => ['skus'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isComparisonEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Product comparison is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $skus = $this->skuListParser->parse($arguments['skus'] ?? null);

        if ($skus === null) {
            return new ToolResult(['error' => 'A non-empty list of string skus is required.']);
        }

        if (count($skus) > self::MAX_SKUS) {
            return new ToolResult(['error' => 'At most ' . self::MAX_SKUS . ' skus may be compared at once.']);
        }

        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);

        $verifiedBySku = [];
        foreach ($verified as $product) {
            $verifiedBySku[$product->sku] = $product;
        }

        $notFound = array_values(array_diff($skus, array_keys($verifiedBySku)));

        return new ToolResult(
            [
                'products' => array_map(fn ($product) => $this->productFormatter->format($product), $verified),
                'not_found' => $notFound,
            ],
            $verified
        );
    }
}
