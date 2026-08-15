<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * A document or store scope does not match the active run store.
 *
 * Messages are generic and never contain store or product identifiers.
 */
final class IndexScopeMismatchException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_SCOPE_MISMATCH,
            new Phrase('The AI shopping assistant index document scope is invalid.'),
            $previous,
            $rebuildResult
        );
    }
}