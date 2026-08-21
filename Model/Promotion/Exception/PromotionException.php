<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Promotion\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Sanitized domain exception for promotion-data validation failures —
 * mirrors CatalogException/MerchandisingBoostException's own discipline.
 */
final class PromotionException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
