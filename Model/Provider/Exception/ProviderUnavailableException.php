<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Exception;

use Magento\Framework\Phrase;

final class ProviderUnavailableException extends ProviderException
{
    public const ERROR_CODE = 'PROVIDER_UNAVAILABLE';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}