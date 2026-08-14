<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when the embedding provider rate-limits the request.
 *
 * Messages are generic and carry no request or account data.
 */
final class EmbeddingRateLimitException extends ProviderException
{
    public const ERROR_CODE = 'embedding_rate_limited';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
