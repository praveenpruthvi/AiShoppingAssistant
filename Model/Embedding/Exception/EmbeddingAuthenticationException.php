<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when the embedding provider rejects the supplied API key.
 *
 * Messages never contain the key or any credential material.
 */
final class EmbeddingAuthenticationException extends ProviderException
{
    public const ERROR_CODE = 'embedding_authentication_failed';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
