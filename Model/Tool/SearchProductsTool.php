<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextResolver;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * search_products — the same retrieval + ranking path ProductContextResolver
 * already uses for up-front product context (Task 3), not raw index access.
 * The ranked candidate SKUs are then live-revalidated before being handed
 * to the model, exactly like every other tool here.
 */
final class SearchProductsTool implements CommerceToolInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ProductContextResolver $productContextResolver,
        private readonly LiveRevalidationServiceInterface $revalidationService,
        private readonly ProductFormatter $productFormatter
    ) {
    }

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Search this store\'s catalogue for products matching a natural-language query. '
            . 'Returns live, verified product data — never invent SKUs, prices, or URLs yourself.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What the customer is looking for.'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isProductDiscoveryEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Product discovery is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $query = $arguments['query'] ?? null;

        if (!is_string($query) || trim($query) === '') {
            return new ToolResult(['error' => 'A non-empty query is required.']);
        }

        $candidates = $this->productContextResolver->resolve($context->storeId, $query);
        $skus = array_map(static fn (SearchCandidate $candidate): string => $candidate->sku, $candidates);
        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);

        return new ToolResult(
            ['products' => array_map(fn ($product) => $this->productFormatter->format($product), $verified)],
            $verified
        );
    }
}
