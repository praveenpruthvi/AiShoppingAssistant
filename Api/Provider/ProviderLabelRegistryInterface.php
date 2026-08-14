<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

/**
 * Supplies trusted display labels for provider identifiers.
 *
 * Labels are static metadata contributed by installed Magento modules through
 * DI, never by customer input. Implementations must never return secrets or
 * expose provider instances. Labels may be used by Admin option sources.
 */
interface ProviderLabelRegistryInterface
{
    public function get(string $identifier): string;
}