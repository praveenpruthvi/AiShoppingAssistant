<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface GeneralConfigInterface
{
    public function isEnabled(): bool;

    public function isStrictStoreOnly(): bool;
}
