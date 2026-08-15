<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The run state does not allow the requested lifecycle call.
 *
 * Messages are generic and never contain run identifiers or store values.
 */
final class IndexRunStateInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_RUN_STATE_INVALID,
            new Phrase('The AI shopping assistant index operation is not allowed in the current state.'),
            $previous,
            $rebuildResult
        );
    }
}