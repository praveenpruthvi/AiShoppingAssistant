<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Sanitized domain exception for merchandising-boost validation/lookup
 * failures — safe to show directly to an admin (mirrors CatalogException's
 * own "never leak raw internals" discipline, applied to this domain).
 */
final class MerchandisingBoostException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
