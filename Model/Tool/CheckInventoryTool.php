<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Magento\Framework\Phrase;

/**
 * check_inventory — live stock/salability status for one or more SKUs.
 *
 * Uses LiveRevalidationServiceInterface::checkAvailability() rather than
 * revalidate(), specifically because revalidate() silently drops anything
 * unavailable — indistinguishable from "doesn't exist." A stock-check
 * tool's entire purpose is to state "out of stock" as a positive answer.
 *
 * Also calls revalidate() separately for the SKUs that turn out to be
 * fully in-stock, purely to obtain the complete RevalidatedProduct data
 * ToolResult::$verifiedProducts needs (AvailabilityStatus intentionally
 * carries no price/url — checkAvailability() doesn't need them). This is
 * a deliberate, accepted small inefficiency (one SKU set can be
 * revalidated twice within a single tool call) traded for correctness:
 * without it, a SKU confirmed here and only here would not be eligible
 * for the Output Validator's already-verified set.
 */
final class CheckInventoryTool implements CommerceToolInterface
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
        return 'check_inventory';
    }

    public function description(): string
    {
        return 'Check live stock/salability status for one or more exact SKUs (up to ' . self::MAX_SKUS . '). '
            . 'Reports "in stock: false" for both out-of-stock and not-found SKUs — check "found" to tell them apart.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'The exact SKUs to check.',
                ],
            ],
            'required' => ['skus'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(ToolContext $context): void
    {
        if (!$this->configurationReader->readCapabilities($context->storeId)->isStockCheckingEnabled()) {
            throw new ToolAuthorizationException(new Phrase('Stock checking is disabled for this store.'));
        }
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $skus = $this->skuListParser->parse($arguments['skus'] ?? null);

        if ($skus === null) {
            return new ToolResult(['error' => 'A non-empty list of string skus is required.']);
        }

        if (count($skus) > self::MAX_SKUS) {
            return new ToolResult(['error' => 'At most ' . self::MAX_SKUS . ' skus may be checked at once.']);
        }

        $statuses = $this->revalidationService->checkAvailability($context->storeId, $context->customerGroupId, $skus);
        $verified = $this->revalidationService->revalidate($context->storeId, $context->customerGroupId, $skus);

        $items = array_map(
            static fn (AvailabilityStatus $status): array => [
                'sku' => $status->sku,
                'found' => $status->found,
                'in_stock' => $status->inStock,
                'name' => $status->name,
            ],
            $statuses
        );

        return new ToolResult(['items' => $items], $verified);
    }
}
