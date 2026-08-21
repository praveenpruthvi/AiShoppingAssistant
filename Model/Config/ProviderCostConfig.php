<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use InvalidArgumentException;

final readonly class ProviderCostConfig implements ProviderCostConfigInterface
{
    /**
     * @param array<string, array{input: float, output: float}> $pricesByProvider
     */
    public function __construct(private array $pricesByProvider)
    {
        foreach ($pricesByProvider as $identifier => $prices) {
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException('Every provider identifier key must be a non-empty string.');
            }

            if (!isset($prices['input'], $prices['output']) || $prices['input'] < 0.0 || $prices['output'] < 0.0) {
                throw new InvalidArgumentException('Every provider price entry must have non-negative input/output prices.');
            }
        }
    }

    public function pricePerThousandInputTokens(string $providerIdentifier): float
    {
        return $this->pricesByProvider[$providerIdentifier]['input'] ?? 0.0;
    }

    public function pricePerThousandOutputTokens(string $providerIdentifier): float
    {
        return $this->pricesByProvider[$providerIdentifier]['output'] ?? 0.0;
    }
}
