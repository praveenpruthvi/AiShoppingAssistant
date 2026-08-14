<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when an embedding provider response cannot be validated.
 *
 * Messages are generic and never contain response bodies, fragments, or any
 * provider-specific data.
 */
final class EmbeddingResponseException extends ProviderException
{
    public const ERROR_CODE = 'embedding_response_invalid';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
