<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Generating or correlating document embeddings failed.
 *
 * Messages are generic and never contain prompts, texts, vector values, or
 * provider-specific details.
 */
final class EmbeddingEnrichmentException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_EMBEDDING_ENRICHMENT,
            new Phrase('The AI shopping assistant index could not be enriched with embeddings.'),
            $previous,
            $rebuildResult
        );
    }
}
