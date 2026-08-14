<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Base class for the sanitized provider exception hierarchy.
 *
 * Messages must always be generic and customer-safe. Machine-readable error
 * codes are stable constants on each concrete subclass. A previous Throwable
 * may be preserved as the cause without copying sensitive request data into
 * the message. Raw provider response bodies are never stored on exceptions.
 */
abstract class ProviderException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        private readonly string $errorCode,
        ?\Throwable $cause = null,
        int $code = 0
    ) {
        parent::__construct($phrase, $cause instanceof \Exception ? $cause : null, $code);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}