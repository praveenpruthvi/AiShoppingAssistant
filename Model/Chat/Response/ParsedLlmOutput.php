<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

/**
 * The raw, structurally-validated (but not yet fact-checked) decoding of
 * ChatResponse::text against LlmResponseSchema. Still fully untrusted for
 * SKU existence/prices/URLs — OutputValidator does that check next.
 */
final readonly class ParsedLlmOutput
{
    /**
     * @param list<array{sku: string, reason: string}> $productSkus
     * @param list<string> $followUpQuestions
     * @param list<array{type: string, skus: list<string>}> $actions
     */
    public function __construct(
        public string $message,
        public array $productSkus,
        public array $followUpQuestions,
        public array $actions
    ) {
    }
}
