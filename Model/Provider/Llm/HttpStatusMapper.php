<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Magento\Framework\Phrase;

/**
 * The same HTTP-status-to-exception mapping AbstractChatProvider's own
 * (private) assertSuccessStatus() already applies for every OpenAI-wire-
 * format provider — extracted here so AnthropicProvider/GeminiProvider
 * (whose request/response shapes differ too much from AbstractChatProvider
 * to extend it) still map transport-level failures onto the identical
 * FallbackEligibilityPolicy-recognized exception hierarchy, rather than
 * re-deriving or drifting from this logic a second and third time.
 * AbstractChatProvider itself is deliberately left untouched — this is a
 * new, additional call site, not a refactor of already-tested code.
 */
final class HttpStatusMapper
{
    public function assertSuccess(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        if (in_array($statusCode, [401, 403], true)) {
            throw new ProviderAuthenticationException(
                new Phrase('The chat provider rejected the request.')
            );
        }

        if ($statusCode === 429) {
            throw new ProviderRateLimitException(
                new Phrase('The chat provider is temporarily limiting requests.')
            );
        }

        if (in_array($statusCode, [408, 504], true)) {
            throw new ProviderTimeoutException(
                new Phrase('The chat provider request timed out.')
            );
        }

        if ($statusCode >= 500) {
            throw new ProviderUnavailableException(
                new Phrase('The chat provider is temporarily unavailable.')
            );
        }

        throw new ProviderInvalidResponseException(
            new Phrase('The chat provider returned an unexpected response.')
        );
    }
}
