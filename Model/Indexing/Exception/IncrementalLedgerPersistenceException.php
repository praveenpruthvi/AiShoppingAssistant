<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Durable incremental work state could not be recorded safely.
 */
final class IncrementalLedgerPersistenceException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_LEDGER_PERSISTENCE,
            new Phrase('The incremental product index work state could not be recorded.'),
            $previous,
            $rebuildResult
        );
    }
}
