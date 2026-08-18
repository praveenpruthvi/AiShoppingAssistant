<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * One tool's execution outcome.
 *
 * `data` is JSON-encoded and fed back to the model as the tool-result
 * message content — it must never contain anything beyond what live
 * Magento data actually says. `verifiedProducts` carries every
 * RevalidatedProduct this call touched so the caller (ToolCallingChatService)
 * can fold them into the Output Validator's already-verified SKU set —
 * a SKU a tool looked up mid-conversation is just as trustworthy as one
 * that came from the original retrieval candidates, and the final answer
 * must be allowed to reference it.
 */
final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<RevalidatedProduct> $verifiedProducts
     */
    public function __construct(
        public array $data,
        public array $verifiedProducts = []
    ) {
    }
}
