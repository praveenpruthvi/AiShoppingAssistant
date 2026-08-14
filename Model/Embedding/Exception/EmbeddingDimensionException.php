<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Magento\Framework\Phrase;

/**
 * Raised when returned vectors do not match the configured dimensions.
 *
 * Messages are generic and never contain the expected or observed dimension
 * values.
 */
final class EmbeddingDimensionException extends ProviderException
{
    public const ERROR_CODE = 'embedding_dimension_mismatch';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
