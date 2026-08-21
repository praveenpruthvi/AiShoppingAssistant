<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Throwable;

/**
 * Distinguishes a "hard" provider failure — one that will keep failing
 * identically on the very next request (an exhausted quota, a revoked or
 * invalid credential) — from a transient one (a slow response, a dropped
 * connection, a single malformed completion) that a subsequent request may
 * well succeed at. Deliberately a much narrower question than
 * FallbackEligibilityPolicyInterface::isEligible(): eligibility asks
 * "may this failure route to a different provider," this asks "should a
 * customer keep being invited to type into a conversation that this
 * specific failure has, in practice, already ended."
 */
interface HardFailureClassifierInterface
{
    public function isHardFailure(Throwable $exception): bool;
}
