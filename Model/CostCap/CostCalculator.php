<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;

/**
 * Real token counts x configured per-provider pricing — never an estimate.
 * A local/self-hosted provider configured at 0/0 (this module's own
 * default for `openai_compatible`) naturally costs 0.0 here, with no
 * special-case branch needed in this class or anywhere the cap is checked.
 */
final class CostCalculator
{
    public function cost(TokenUsage $usage, string $providerIdentifier, ProviderCostConfigInterface $providerCost): float
    {
        $inputCost = ($usage->inputTokens / 1000) * $providerCost->pricePerThousandInputTokens($providerIdentifier);
        $outputCost = ($usage->outputTokens / 1000) * $providerCost->pricePerThousandOutputTokens($providerIdentifier);

        return $inputCost + $outputCost;
    }
}
