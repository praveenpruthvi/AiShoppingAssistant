<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The live store alias is missing, mixed, foreign, or incompatible.
 */
final class IncrementalIndexTargetInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_TARGET_INVALID,
            new Phrase('The AI shopping assistant incremental index target is invalid.'),
            $previous,
            $rebuildResult
        );
    }
}
