<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Magento\Framework\Phrase;

/**
 * Publishing an incremental product-index message failed.
 */
final class IncrementalQueuePublishFailedException extends ProductIndexingException
{
    public function __construct()
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_QUEUE_PUBLISH_FAILED,
            new Phrase('The AI shopping assistant incremental index message could not be published.'),
            null
        );
    }
}
