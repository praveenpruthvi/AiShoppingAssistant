<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\CostCap;

interface CostCapCheckerInterface
{
    /**
     * True only when the cost cap is configured (capAmount() > 0), the
     * current period's accumulated cost has reached it, AND override is
     * not allowed. Any error reading config or usage — including store
     * resolution — is caught internally and resolved to false: this
     * method must never throw, and must never make a tracking failure
     * look like "cap reached," since that would silently take down a
     * working customer channel.
     */
    public function isBlocking(): bool;
}
