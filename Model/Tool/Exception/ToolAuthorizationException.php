<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Raised by CommerceToolInterface::authorize() when a tool is not allowed
 * for the given context — most commonly its capability toggle is disabled.
 * Deliberately outside the Provider* hierarchy: this is a policy/permission
 * rejection, not a provider I/O failure, so it has no fallback story,
 * matching the precedent set by ChatInputException/StoreScopeException.
 */
final class ToolAuthorizationException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
