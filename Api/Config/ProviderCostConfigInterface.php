<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface ProviderCostConfigInterface
{
    /**
     * Both methods return 0.0 for a provider identifier this store has no
     * configured pricing for (including an unrecognized/future identifier)
     * — the same fail-safe default a local/self-hosted provider like
     * `openai_compatible` uses deliberately, since there is typically no
     * per-token API cost for those.
     */
    public function pricePerThousandInputTokens(string $providerIdentifier): float;

    public function pricePerThousandOutputTokens(string $providerIdentifier): float;
}
