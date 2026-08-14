<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when the embedding provider is unreachable or returns server errors.
 *
 * Messages are generic and never contain the endpoint, status code, or response
 * body.
 */
final class EmbeddingUnavailableException extends ProviderException
{
    public const ERROR_CODE = 'embedding_provider_unavailable';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
