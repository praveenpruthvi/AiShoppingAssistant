<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Provider\HardFailureClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfirmedDownException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Throwable;

/**
 * A rate limit (quota exhausted for the current window — typically an
 * entire day on a free-tier key) and an authentication failure (an invalid
 * or revoked API key) are both account-level problems that an immediate,
 * or even a several-second-later, retry cannot fix: the very next request
 * against the same credential will fail exactly the same way. Every other
 * ProviderException (timeout, transport, invalid response, generic
 * unavailability) is a single-request-scoped problem that a fresh request
 * has a genuine chance of not hitting again — the module's existing
 * retry/fallback machinery already treats those as worth retrying.
 *
 * Covers both the chat-provider exception hierarchy (Provider*Exception)
 * and the separate embedding-provider one (Embedding*Exception, used
 * during retrieval's query-embedding step) — an exhausted quota or a bad
 * key is exactly as unrecoverable on the embedding path as on the chat
 * path, even though the two hierarchies are sibling ProviderException
 * subclasses rather than sharing a common Rate-Limit/Authentication base.
 *
 * ProviderConfirmedDownException (Task 46's alternating-message fix) is
 * also hard: it is thrown specifically when a role's circuit breaker is
 * already open because of an earlier hard failure and this call skipped
 * the provider entirely — the underlying cause has not gone away just
 * because this particular call never re-attempted it.
 */
final class HardFailureClassifier implements HardFailureClassifierInterface
{
    public function isHardFailure(Throwable $exception): bool
    {
        return $exception instanceof ProviderRateLimitException
            || $exception instanceof ProviderAuthenticationException
            || $exception instanceof EmbeddingRateLimitException
            || $exception instanceof EmbeddingAuthenticationException
            || $exception instanceof ProviderConfirmedDownException;
    }
}
