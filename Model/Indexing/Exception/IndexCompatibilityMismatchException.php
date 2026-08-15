<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The document or embedding configuration is incompatible with the run.
 *
 * Messages are generic and never expose fingerprints, model names, or vector
 * values.
 */
final class IndexCompatibilityMismatchException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_COMPATIBILITY_MISMATCH,
            new Phrase('The AI shopping assistant index embedding configuration is incompatible.'),
            $previous,
            $rebuildResult
        );
    }
}