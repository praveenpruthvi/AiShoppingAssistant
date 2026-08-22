<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\FallbackEligibilityPolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\HardFailureClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\BackoffSleeperInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfirmedDownException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Magento\Framework\Phrase;

/**
 * Wraps ChatGenerationService with retry, a circuit breaker, and a
 * configured fallback provider — implements the same
 * ChatGenerationServiceInterface, so it is a pure decorator: ChatEntryPipeline
 * and every other caller keep calling chat() exactly as before, unaware
 * fallback exists, per etc/di.xml swapping the interface preference to
 * this class instead of ChatGenerationService directly. The undecorated
 * ChatGenerationService is injected by its concrete class name specifically
 * to get the primary-only implementation, not this decorator, avoiding a
 * DI cycle.
 *
 * Sequence per architecture.md: primary call -> limited retry -> circuit
 * breaker -> fallback provider -> (if that also fails or isn't configured)
 * propagate the last failure, which ChatEntryPipeline turns into the
 * existing SafeResponse fallback — product search keeps working even if
 * every LLM call fails.
 *
 * Only FallbackEligibilityPolicy-eligible (transient availability) failures
 * are retried, count toward the circuit breaker, or trigger a fallback
 * attempt at all — a safety/configuration/authentication failure from the
 * primary provider propagates immediately, exactly as it did before this
 * class existed, and never causes a fallback provider to be consulted
 * (fallback must never be used to bypass a safety boundary).
 *
 * HardFailureClassifier draws a second, narrower line inside "eligible":
 * a rate limit or an authentication failure will fail identically on an
 * immediate retry against the same provider, so neither is given the
 * local backoff-retry loop a timeout/transport failure gets (see
 * attemptPrimaryWithRetry()) — retrying a 429/401 three times in ~1.4s
 * only burns quota and adds latency, it does not change the outcome.
 * Both also force their circuit open on this single occurrence rather
 * than waiting for the configured failure_threshold's usual multiple
 * consecutive failures: an exhausted quota or a bad key is not a blip
 * that might not recur on the very next request, it is confirmed to
 * recur on every request until the underlying account problem is fixed,
 * so ChatWidget's circuit-breaker-driven hide safeguard (Task 44) should
 * react to it immediately rather than only after several more customers
 * each hit the same guaranteed failure. Rate limit failures remain
 * fallback-eligible, unlike authentication — a different provider is not
 * subject to the same account's quota, so trying it is still legitimate;
 * only the local same-provider retry is skipped.
 *
 * recordHardFailure()/wasOpenedByHardFailure() (Task 46's alternating-
 * message fix) keep this correct across the cooldown, not only on the
 * one call that actually trips the breaker: once a role's circuit is
 * open, every later call skips it entirely and never sees a fresh
 * exception, so attemptFallback()'s "nothing left to try" branch has to
 * synthesize one — confirmedOrGenericallyUnavailableException() asks the
 * breaker whether it was opened by a hard failure and throws
 * ProviderConfirmedDownException (still hard, per HardFailureClassifier)
 * instead of the generic, soft ProviderUnavailableException in that
 * case, so the customer-facing message stays "assistant_down" for the
 * whole cooldown rather than silently reverting to "just try again"
 * after the very first request.
 *
 * primaryFailureWasHard() (Task 47) closes a related gap: fallback's OWN
 * circuit only force-opens on a fallback exception that is ITSELF hard.
 * An ordinary transient fallback failure (its own circuit still below
 * failure_threshold) left FALLBACK's circuit closed even while PRIMARY
 * was already confirmed hard-down — ChatWidget's render gate reads
 * exactly that state and correctly treats a closed fallback circuit as
 * "still genuinely usable," so the widget kept reappearing on a refresh
 * even though every real request during that window was failing on
 * BOTH primary and fallback. Fixed by upgrading a fallback failure to
 * hard (recordHardFailure(), and the thrown exception itself becomes
 * ProviderConfirmedDownException) whenever primaryFailureWasHard()
 * confirms primary's OWN failure — this call's or an earlier one's
 * already-open circuit — was hard. Deliberately narrow: this only fires
 * when fallback has just been ACTUALLY ATTEMPTED and ACTUALLY FAILED —
 * a fallback provider that is genuinely healthy and succeeding is
 * completely untouched by this, since recordSuccess() runs instead and
 * none of this failure-handling code executes at all. Do not widen this
 * to hide the widget merely because primary is hard-down regardless of
 * fallback's outcome — a working fallback genuinely means the assistant
 * is still answering real questions, and hiding it in that case would
 * be actively wrong, not merely overcautious (this is precisely the
 * scenario ChatWidget's own fallback-circuit check exists to protect).
 */
final class FallbackChatGenerationService implements ChatGenerationServiceInterface
{
    /** 1 initial attempt + 2 retries against the primary provider. */
    private const MAX_PRIMARY_ATTEMPTS = 3;

    /**
     * Short, millisecond-scale backoff — a customer is waiting on this HTTP
     * response, unlike the second/minute-scale backoff used for async
     * queue recovery elsewhere in this module.
     */
    private const RETRY_BASE_DELAY_MS = 200;
    private const RETRY_MAX_DELAY_MS = 800;

    public function __construct(
        private readonly ChatGenerationService $primaryService,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ConfiguredProviderResolverInterface $providerResolver,
        private readonly SecretReaderInterface $secretReader,
        private readonly FallbackEligibilityPolicyInterface $eligibilityPolicy,
        private readonly HardFailureClassifierInterface $hardFailureClassifier,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly BackoffSleeperInterface $sleeper
    ) {
    }

    public function chat(int $storeId, array $messages, array $tools = [], ?array $responseSchema = null): ChatResponse
    {
        $primaryException = null;

        if (!$this->circuitBreaker->isOpen($storeId, CircuitBreakerInterface::ROLE_PRIMARY)) {
            try {
                $response = $this->attemptPrimaryWithRetry($storeId, $messages, $tools, $responseSchema);
                $this->circuitBreaker->recordSuccess($storeId, CircuitBreakerInterface::ROLE_PRIMARY);

                return $response;
            } catch (ProviderException $exception) {
                $isHardFailure = $this->hardFailureClassifier->isHardFailure($exception);
                $isEligible = $this->eligibilityPolicy->isEligible($exception);

                // A hard failure (exhausted quota, invalid/revoked key)
                // still records against the circuit breaker even when it
                // is not fallback-eligible (authentication never is — see
                // this class's own docblock): the circuit breaker is also
                // ChatWidget's only health signal (Task 44), and a bad key
                // must be visible there even though, as a safety boundary,
                // it must never itself trigger a fallback attempt.
                if ($isEligible || $isHardFailure) {
                    $fallbackConfig = $this->readFallbackConfig($storeId);

                    if ($isHardFailure) {
                        $this->circuitBreaker->recordHardFailure(
                            $storeId,
                            CircuitBreakerInterface::ROLE_PRIMARY,
                            $fallbackConfig->cooldownSeconds()
                        );
                    } else {
                        $this->circuitBreaker->recordFailure(
                            $storeId,
                            CircuitBreakerInterface::ROLE_PRIMARY,
                            $fallbackConfig->failureThreshold(),
                            $fallbackConfig->cooldownSeconds()
                        );
                    }
                }

                if (!$isEligible) {
                    throw $exception;
                }

                $primaryException = $exception;
            }
        }

        return $this->attemptFallback($storeId, $messages, $tools, $responseSchema, $primaryException);
    }

    private function attemptPrimaryWithRetry(int $storeId, array $messages, array $tools, ?array $responseSchema): ChatResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->primaryService->chat($storeId, $messages, $tools, $responseSchema);
            } catch (ProviderException $exception) {
                if (!$this->eligibilityPolicy->isEligible($exception)
                    || $this->hardFailureClassifier->isHardFailure($exception)
                    || $attempt >= self::MAX_PRIMARY_ATTEMPTS
                ) {
                    throw $exception;
                }

                $this->sleeper->sleepMilliseconds($this->backoffDelayMilliseconds($attempt));
            }
        }
    }

    private function backoffDelayMilliseconds(int $attempt): int
    {
        $delay = self::RETRY_BASE_DELAY_MS * (2 ** max(0, $attempt - 1));

        return min(self::RETRY_MAX_DELAY_MS, $delay);
    }

    /**
     * @param list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    private function attemptFallback(
        int $storeId,
        array $messages,
        array $tools,
        ?array $responseSchema,
        ?ProviderException $primaryException
    ): ChatResponse {
        $fallbackProvider = $this->resolveFallbackProvider($storeId);

        if ($fallbackProvider === null || $this->circuitBreaker->isOpen($storeId, CircuitBreakerInterface::ROLE_FALLBACK)) {
            throw $primaryException ?? $this->confirmedOrGenericallyUnavailableException($storeId);
        }

        try {
            $response = $this->callFallbackProvider($fallbackProvider, $storeId, $messages, $tools, $responseSchema);
            $this->circuitBreaker->recordSuccess($storeId, CircuitBreakerInterface::ROLE_FALLBACK);

            return $response->withFallbackUsed(true);
        } catch (ProviderException $exception) {
            $isFallbackHardFailure = $this->hardFailureClassifier->isHardFailure($exception);
            $isPrimaryConfirmedHard = $this->primaryFailureWasHard($storeId, $primaryException);
            $isHardFailure = $isFallbackHardFailure || $isPrimaryConfirmedHard;

            if ($this->eligibilityPolicy->isEligible($exception) || $isHardFailure) {
                $fallbackConfig = $this->readFallbackConfig($storeId);

                if ($isHardFailure) {
                    $this->circuitBreaker->recordHardFailure(
                        $storeId,
                        CircuitBreakerInterface::ROLE_FALLBACK,
                        $fallbackConfig->cooldownSeconds()
                    );
                } else {
                    $this->circuitBreaker->recordFailure(
                        $storeId,
                        CircuitBreakerInterface::ROLE_FALLBACK,
                        $fallbackConfig->failureThreshold(),
                        $fallbackConfig->cooldownSeconds()
                    );
                }
            }

            // Primary was already confirmed hard-down (this call's own
            // exception, or an earlier call's already-open circuit) and
            // fallback — the only remaining path — just genuinely failed
            // too, even if fallback's own exception type isn't itself
            // hard. Upgrading to ProviderConfirmedDownException here
            // (Task 47's fallback-not-yet-tripped fix) keeps the
            // customer-facing reason code, the fallback circuit, and
            // ChatWidget's render gate all consistent: "both paths are
            // currently broken" should read the same everywhere, not
            // only once fallback's OWN failure count separately reaches
            // its configured threshold. A fallback exception that's
            // already independently hard is thrown as-is — no need to
            // wrap what's already correctly classified.
            if ($isPrimaryConfirmedHard && !$isFallbackHardFailure) {
                throw new ProviderConfirmedDownException(
                    new Phrase('The chat provider is still confirmed unavailable.'),
                    $exception
                );
            }

            throw $exception;
        }
    }

    /**
     * Whether the failure that got this call into attemptFallback() at
     * all was itself hard: either this call's own primary attempt threw
     * a hard exception ($primaryException is non-null in that case), or
     * this call never even attempted primary because its circuit was
     * already open — necessarily from an earlier hard failure, since
     * only recordHardFailure() force-opens a circuit on a single
     * occurrence (see chat()'s own control flow: attemptFallback() is
     * reached with $primaryException === null only via that skip path).
     */
    private function primaryFailureWasHard(int $storeId, ?ProviderException $primaryException): bool
    {
        if ($primaryException !== null) {
            return $this->hardFailureClassifier->isHardFailure($primaryException);
        }

        return $this->circuitBreaker->wasOpenedByHardFailure($storeId, CircuitBreakerInterface::ROLE_PRIMARY);
    }

    /**
     * Called only when this call never even attempted the primary
     * provider, because its circuit breaker was already open
     * (`$primaryException` is null specifically in that case — see
     * chat()'s own control flow, the only other way attemptFallback() is
     * reached is with a real caught exception). Without this, every such
     * call — every request made during a hard failure's cooldown — would
     * throw a generic ProviderUnavailableException, which
     * HardFailureClassifier does NOT treat as hard, silently downgrading
     * the customer-facing message from "assistant_down" back to
     * "assistant_unavailable" even though the underlying cause has not
     * changed. Task 45's own live verification caught exactly this: a
     * real invalid-key run showed call 1 correctly reporting
     * assistant_down, then calls 2-5 (circuit already open) incorrectly
     * reverting to assistant_unavailable.
     */
    private function confirmedOrGenericallyUnavailableException(int $storeId): ProviderException
    {
        if ($this->circuitBreaker->wasOpenedByHardFailure($storeId, CircuitBreakerInterface::ROLE_PRIMARY)) {
            return new ProviderConfirmedDownException(
                new Phrase('The chat provider is still confirmed unavailable.')
            );
        }

        return new ProviderUnavailableException(
            new Phrase('The chat provider is temporarily unavailable.')
        );
    }

    private function resolveFallbackProvider(int $storeId): ?LlmProviderInterface
    {
        try {
            if (!$this->readFallbackConfig($storeId)->isEnabled()) {
                return null;
            }

            return $this->providerResolver->fallbackLlmProvider($storeId);
        } catch (\Throwable) {
            // A misconfigured fallback is treated the same as no fallback
            // at all — never a harder failure than having none configured.
            return null;
        }
    }

    /**
     * @param list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    private function callFallbackProvider(
        LlmProviderInterface $provider,
        int $storeId,
        array $messages,
        array $tools,
        ?array $responseSchema
    ): ChatResponse {
        $config = $this->readFallbackConfig($storeId);
        $apiKey = $this->readFallbackApiKey($storeId);

        $request = new ChatRequest(
            storeId: $storeId,
            messages: $messages,
            model: $config->model(),
            baseUrl: $config->baseUrl(),
            apiKey: $apiKey,
            timeoutSeconds: $config->timeoutSeconds(),
            tools: $tools,
            responseSchema: $responseSchema
            // maxOutputTokens intentionally left at ChatRequest's own
            // default: FallbackConfigInterface has no maxOutputTokens
            // setting (verified — unlike LlmConfigInterface), so there is
            // nothing store-configured to read here.
        );

        return $provider->chat($request);
    }

    private function readFallbackConfig(int $storeId): FallbackConfigInterface
    {
        try {
            return $this->configurationReader->readFallback($storeId);
        } catch (ConfigurationException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The fallback chat configuration is incomplete.'),
                $cause
            );
        }
    }

    private function readFallbackApiKey(int $storeId): SecretValue
    {
        try {
            return $this->secretReader->getFallbackLlmApiKey($storeId);
        } catch (ConfigurationException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The fallback chat API key could not be read.'),
                $cause
            );
        }
    }
}
