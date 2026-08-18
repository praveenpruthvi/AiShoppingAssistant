<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Raised when a raw customer message fails input validation (empty, too
 * long, invalid UTF-8) before it ever reaches the scope classifier or a
 * provider.
 *
 * Deliberately not part of the Provider* exception hierarchy: this is a
 * rejection of the caller's raw input, not a provider I/O failure, so
 * FallbackEligibilityPolicy has no reason to ever consider it eligible for
 * fallback (mirrors ConfigurationException and StoreScopeException, which
 * are also their own small top-level exceptions for the same reason).
 */
final class ChatInputException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
