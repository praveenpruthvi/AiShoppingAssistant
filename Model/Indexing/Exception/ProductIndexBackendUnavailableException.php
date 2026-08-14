<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The assistant-index backend is not configured or not available.
 *
 * Thrown instead of silently writing nowhere: indexing must never fail open.
 */
final class ProductIndexBackendUnavailableException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_BACKEND_UNAVAILABLE,
            new Phrase('The AI shopping assistant index is not available.'),
            $previous,
            $rebuildResult
        );
    }
}
