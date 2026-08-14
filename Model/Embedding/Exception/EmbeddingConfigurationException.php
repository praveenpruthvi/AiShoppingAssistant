<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when embedding configuration is missing or violates provider policy.
 *
 * Messages are always generic and never contain configuration values, URLs,
 * identifiers, or secrets.
 */
final class EmbeddingConfigurationException extends ProviderException
{
    public const ERROR_CODE = 'embedding_configuration_invalid';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
