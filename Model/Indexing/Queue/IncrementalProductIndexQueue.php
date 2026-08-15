<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

/**
 * Stable Magento message queue names for incremental product indexing.
 */
final class IncrementalProductIndexQueue
{
    public const TOPIC = 'aavirbhava.ai_shopping_assistant.product.incremental_index';
    public const QUEUE = 'aavirbhava.ai_shopping_assistant.product.incremental_index';
    public const CONSUMER = 'aavirbhava.ai_shopping_assistant.product.incremental_index';
}
