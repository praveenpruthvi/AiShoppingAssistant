<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;

final readonly class GeneralConfig implements GeneralConfigInterface
{
    public function __construct(
        private bool $enabled,
        private bool $strictStoreOnly,
        private int $maxConversationMessages,
        private bool $tokenOptimizationEnabled
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

    public function maxConversationMessages(): int
    {
        return $this->maxConversationMessages;
    }

    public function isTokenOptimizationEnabled(): bool
    {
        return $this->tokenOptimizationEnabled;
    }
}
