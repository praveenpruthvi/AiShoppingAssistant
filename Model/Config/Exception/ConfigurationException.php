<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

final class ConfigurationException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
