<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Sanitized domain exception for cost-cap tracking/persistence failures —
 * mirrors PromotionException/MerchandisingBoostException's own discipline.
 * Every caller of DbCostUsageTracker treats this (and any other Throwable)
 * as a fail-open signal, never a hard failure — see CostCapEnforcer/
 * CostUsageRecorder.
 */
final class CostCapException extends LocalizedException
{
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
