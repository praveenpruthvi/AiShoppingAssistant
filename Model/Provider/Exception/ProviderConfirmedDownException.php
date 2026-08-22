<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Exception;

use Magento\Framework\Phrase;

/**
 * Thrown when a provider role's circuit breaker is already open BECAUSE OF
 * a previously-confirmed hard failure (Task 45) and this call skipped the
 * provider entirely — never re-attempting it, so no fresh exception from
 * the provider itself is available this call. Deliberately its own,
 * distinct exception type rather than reusing ProviderRateLimitException/
 * ProviderAuthenticationException: this call genuinely did not experience
 * either of those, it only inherited an already-open breaker that was
 * opened by one of them on an earlier call. HardFailureClassifier still
 * treats it as hard, so a request made while the breaker is open in this
 * state gets the same "assistant_down, stop the chat" customer-facing
 * outcome as the original request that tripped it — not a silent downgrade
 * to a transient "just try again" message for every request made during
 * the cooldown, which is exactly the bug this class exists to prevent.
 */
final class ProviderConfirmedDownException extends ProviderException
{
    public const ERROR_CODE = 'PROVIDER_CONFIRMED_DOWN';

    public function __construct(Phrase $phrase, ?\Throwable $cause = null)
    {
        parent::__construct($phrase, self::ERROR_CODE, $cause);
    }
}
