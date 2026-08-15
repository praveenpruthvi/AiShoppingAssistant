<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Magento\Framework\Phrase;

/**
 * Bounded incremental reconciliation could not be recorded or scheduled safely.
 */
final class IncrementalReconciliationException extends ProductIndexingException
{
    public function __construct()
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_RECONCILIATION_FAILED,
            new Phrase('The incremental product index reconciliation could not be completed.')
        );
    }
}
