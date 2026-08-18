<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * get_product_details — one exact SKU -> live-revalidated detail, reusing
 * LiveRevalidationService (Task 4), never a second Magento API path.
 */
final class GetProductDetailsTool implements CommerceToolInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly ProductFormatter $productFormatter
    ) {
    }

    public function name(): string
    {
        return 'get_product_details';
    }

    public function description(): string
    {
        return 'Look up live, verified details for one exact SKU already known from search results or the '
            . 'conversation. Returns nothing if the SKU does not exist or is not currently available.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'The exact SKU to look up.'],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isProductDetailsEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Product detail lookup is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $sku = $arguments['sku'] ?? null;

        if (!is_string($sku) || trim($sku) === '') {
            return new ToolResult(['error' => 'A non-empty sku is required.']);
        }

        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, [$sku]);

        if ($verified === []) {
            return new ToolResult(['found' => false]);
        }

        return new ToolResult(
            ['found' => true, 'product' => $this->productFormatter->format($verified[0])],
            $verified
        );
    }
}
