<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when a text batch cannot be embedded.
 *
 * Messages are generic and never contain the offending text or any customer
 * data.
 */
final class EmbeddingInputException extends ProviderException
{
    public const ERROR_CODE = 'embedding_input_invalid';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
