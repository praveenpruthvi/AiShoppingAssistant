<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Provider\FallbackEligibilityPolicyInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Throwable;

/**
 * Fallback is permitted only for transient provider availability failures.
 *
 * Configuration, authentication, invalid-response, refusal, and policy
 * violations never trigger fallback: a failure must never be used to bypass a
 * safety boundary, and unknown exceptions fail closed.
 */
final class FallbackEligibilityPolicy implements FallbackEligibilityPolicyInterface
{
    public function isEligible(Throwable $exception): bool
    {
        return $exception instanceof ProviderTimeoutException
            || $exception instanceof ProviderRateLimitException
            || $exception instanceof ProviderTransportException
            || $exception instanceof ProviderUnavailableException;
    }
}
