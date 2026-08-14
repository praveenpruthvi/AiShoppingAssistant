<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Store\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Sanitized exception for store scope resolution failures.
 *
 * Never carries store codes, configuration values, or internal details.
 */
final class StoreScopeException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}