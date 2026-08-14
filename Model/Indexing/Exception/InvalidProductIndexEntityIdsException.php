<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Incremental scheduling was requested with invalid product entity ids.
 */
final class InvalidProductIndexEntityIdsException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INVALID_ENTITY_IDS,
            new Phrase('The product index update references an invalid product.'),
            $previous,
            $rebuildResult
        );
    }
}
