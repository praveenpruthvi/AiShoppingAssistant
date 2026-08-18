<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Magento\Framework\Phrase;

/**
 * A search response was malformed or could not be verified.
 *
 * Messages are generic and never expose raw response bodies or hit content.
 */
final class SearchResponseInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null)
    {
        parent::__construct(
            self::ERROR_SEARCH_RESPONSE_INVALID,
            new Phrase('The AI shopping assistant search response could not be verified.'),
            $previous
        );
    }
}
