<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Raised when a requested tool name is not in the registered allowlist.
 * Mirrors Model\Provider\Exception\ProviderNotFoundException: the
 * registry IS the allowlist, and an unregistered name always fails
 * closed rather than being resolved dynamically.
 */
final class ToolNotFoundException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
