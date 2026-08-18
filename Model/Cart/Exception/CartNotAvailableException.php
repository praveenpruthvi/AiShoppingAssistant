<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Cart\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Raised by CartResolverInterface::resolve() when no cart can be identified
 * or accessed for the given context — no cart id was supplied, the id does
 * not resolve to a real quote, or the resolved quote does not belong to the
 * requested store. Deliberately outside the Provider* hierarchy (not a
 * provider I/O failure) and outside Tool\Exception (this is a cart-domain
 * concern, reusable by anything that needs a cart, not tool-specific),
 * matching the precedent set by StoreScopeException/ChatInputException.
 */
final class CartNotAvailableException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
