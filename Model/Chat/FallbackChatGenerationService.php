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
use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\BackoffSleeperInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
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
                if (!$this->eligibilityPolicy->isEligible($exception)) {
                    throw $exception;
                }

                $primaryException = $exception;
                $fallbackConfig = $this->readFallbackConfig($storeId);
                $this->circuitBreaker->recordFailure(
                    $storeId,
                    CircuitBreakerInterface::ROLE_PRIMARY,
                    $fallbackConfig->failureThreshold(),
                    $fallbackConfig->cooldownSeconds()
                );
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
                if (!$this->eligibilityPolicy->isEligible($exception) || $attempt >= self::MAX_PRIMARY_ATTEMPTS) {
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
            throw $primaryException ?? new ProviderUnavailableException(
                new Phrase('The chat provider is temporarily unavailable.')
            );
        }

        try {
            $response = $this->callFallbackProvider($fallbackProvider, $storeId, $messages, $tools, $responseSchema);
            $this->circuitBreaker->recordSuccess($storeId, CircuitBreakerInterface::ROLE_FALLBACK);

            return $response->withFallbackUsed(true);
        } catch (ProviderException $exception) {
            if ($this->eligibilityPolicy->isEligible($exception)) {
                $fallbackConfig = $this->readFallbackConfig($storeId);
                $this->circuitBreaker->recordFailure(
                    $storeId,
                    CircuitBreakerInterface::ROLE_FALLBACK,
                    $fallbackConfig->failureThreshold(),
                    $fallbackConfig->cooldownSeconds()
                );
            }

            throw $exception;
        }
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
