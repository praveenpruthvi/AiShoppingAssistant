<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when the HTTP transport cannot complete an embedding request.
 *
 * Messages are generic and never contain URLs, headers, bodies, or credentials.
 */
final class EmbeddingTransportException extends ProviderException
{
    public const ERROR_CODE = 'embedding_transport_failed';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
