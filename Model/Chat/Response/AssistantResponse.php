<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

use InvalidArgumentException;

/**
 * The structured response contract: never bare LLM prose. Every SKU in
 * `products` has already passed the Output Validator (mentioned by the LLM
 * AND present in the live-revalidated set); every product fact besides
 * `reason` comes from live Magento data via ProductResult::$product.
 */
final readonly class AssistantResponse
{
    /**
     * @param list<ProductResult> $products
     * @param list<string> $followUpQuestions
     * @param list<AssistantAction> $actions
     */
    public function __construct(
        public string $message,
        public array $products,
        public array $followUpQuestions,
        public array $actions,
        public ResponseMetadata $metadata
    ) {
        if ($message === '') {
            throw new InvalidArgumentException('An assistant response requires a non-empty message.');
        }

        foreach ($products as $product) {
            if (!$product instanceof ProductResult) {
                throw new InvalidArgumentException('Every assistant response product must be a ProductResult.');
            }
        }

        foreach ($followUpQuestions as $question) {
            if (!is_string($question) || $question === '') {
                throw new InvalidArgumentException('Every follow-up question must be a non-empty string.');
            }
        }

        foreach ($actions as $action) {
            if (!$action instanceof AssistantAction) {
                throw new InvalidArgumentException('Every assistant response action must be an AssistantAction.');
            }
        }
    }
}
