<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\BlogContentSearcherInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Magento\Framework\Phrase;

/**
 * search_store_content — a unified, keyword-only search across CMS pages,
 * blog posts (if a blog module is installed), and products, distinct from
 * search_products (semantic/RAG retrieval against the assistant's own
 * product index). Useful for "do you have a returns policy page" or "any
 * blog posts about X" as well as attribute/keyword product lookups
 * search_products' semantic ranking might not surface well.
 *
 * Gated by capabilities.policy_search_enabled, the same "excluded from
 * the offered tool set entirely when disabled" pattern every other tool
 * here follows.
 */
final class SearchStoreContentTool implements CommerceToolInterface
{
    private const RESULTS_PER_CONTENT_TYPE = 5;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CmsPageContentSearcher $cmsPageContentSearcher,
        private readonly BlogContentSearcherInterface $blogContentSearcher,
        private readonly ProductContentSearcher $productContentSearcher,
        private readonly LiveRevalidationServiceInterface $revalidationService
    ) {
    }

    public function name(): string
    {
        return 'search_store_content';
    }

    public function description(): string
    {
        return 'Search this store\'s content by keyword — CMS pages (policies, FAQs, informational pages), '
            . 'blog posts (when available), and products by name/category/description. Closer to a keyword '
            . 'search than search_products\' semantic ranking; use it for questions like "do you have a '
            . 'returns policy" as well as keyword-based product lookups. Never invent a page, post, SKU, '
            . 'price, or URL beyond what this tool returns.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keywords to search the store\'s content for.'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isPolicySearchEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Store content search is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $query = $arguments['query'] ?? null;

        if (!is_string($query) || trim($query) === '') {
            return new ToolResult(['error' => 'A non-empty query is required.']);
        }

        $query = trim($query);

        $cmsMatches = $this->cmsPageContentSearcher->search($context->storeId, $query, self::RESULTS_PER_CONTENT_TYPE);
        $blogMatches = $this->blogContentSearcher->search($context->storeId, $query, self::RESULTS_PER_CONTENT_TYPE);

        $skus = $this->productContentSearcher->searchSkus($context->storeId, $query, self::RESULTS_PER_CONTENT_TYPE);
        $verifiedProducts = $skus === []
            ? []
            : $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);
        $productMatches = array_map(
            fn (RevalidatedProduct $product): StoreContentMatch => $this->toProductMatch($product),
            $verifiedProducts
        );

        $results = [...$cmsMatches, ...$blogMatches, ...$productMatches];

        return new ToolResult(
            ['results' => array_map(static fn (StoreContentMatch $match): array => $match->toArray(), $results)],
            $verifiedProducts
        );
    }

    private function toProductMatch(RevalidatedProduct $product): StoreContentMatch
    {
        return new StoreContentMatch(
            StoreContentMatch::TYPE_PRODUCT,
            $product->sku,
            $product->name,
            '',
            $product->url,
            $product->price,
            $product->specialPrice
        );
    }
}
