<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;

final readonly class GeneralConfig implements GeneralConfigInterface
{
    public function __construct(
        private bool $enabled,
        private bool $strictStoreOnly
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isStrictStoreOnly(): bool
    {
        return $this->strictStoreOnly;
    }
}
