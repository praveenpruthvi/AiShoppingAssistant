<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Magento\Framework\Phrase;

/**
 * A read-time search query could not be executed against the backend.
 *
 * Messages are generic and never contain cluster hosts, credentials, or query
 * content.
 */
final class SearchQueryFailedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null)
    {
        parent::__construct(
            self::ERROR_SEARCH_QUERY_FAILED,
            new Phrase('The AI shopping assistant search could not be completed.'),
            $previous
        );
    }
}
