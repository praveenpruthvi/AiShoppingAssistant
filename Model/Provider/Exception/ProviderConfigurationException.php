<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Exception;

use Magento\Framework\Phrase;

final class ProviderConfigurationException extends ProviderException
{
    public const ERROR_CODE = 'PROVIDER_CONFIGURATION_ERROR';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
