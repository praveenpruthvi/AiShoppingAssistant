<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Throwable;

/**
 * Decides whether a failure is allowed to trigger the configured fallback.
 *
 * The policy is deliberately conservative. Safety and validation failures are
 * never eligible for fallback, and unknown exceptions fail closed. Only
 * transient availability failures may use the fallback provider.
 */
interface FallbackEligibilityPolicyInterface
{
    public function isEligible(Throwable $exception): bool;
}
