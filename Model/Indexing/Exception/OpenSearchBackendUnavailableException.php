<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The OpenSearch backend is not reachable or its transport failed.
 *
 * Messages are generic and never contain cluster hosts, credentials, request
 * or response bodies, documents, or embeddings.
 */
final class OpenSearchBackendUnavailableException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_OPENSEARCH_BACKEND_UNAVAILABLE,
            new Phrase('The AI shopping assistant search backend is not available.'),
            $previous,
            $rebuildResult
        );
    }
}
