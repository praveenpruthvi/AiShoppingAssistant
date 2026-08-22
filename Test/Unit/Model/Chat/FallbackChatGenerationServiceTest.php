<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\FallbackEligibilityPolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\BackoffSleeperInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\FallbackChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ToolCallingChatService;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfirmedDownException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\FallbackEligibilityPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\HardFailureClassifier;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FallbackChatGenerationService::class)]
final class FallbackChatGenerationServiceTest extends TestCase
{
    private const STORE_ID = 3;

    /**
     * @var list<ChatMessage>
     */
    private array $messages;

    protected function setUp(): void
    {
        $this->messages = [new ChatMessage('user', 'Show me waterproof jackets.')];
    }

    public function testSuccessfulPrimaryCallReturnsDirectlyWithoutFallback(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::once())->method('chat')->willReturn($this->response('ok', 'openai'));

        $fallbackProviderResolver = $this->providerResolver($primaryProvider, null);
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::once())->method('recordSuccess')->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY);
        $circuitBreaker->expects(self::never())->method('recordFailure');

        $sleeper = $this->createMock(BackoffSleeperInterface::class);
        $sleeper->expects(self::never())->method('sleepMilliseconds');

        $service = $this->service($fallbackProviderResolver, circuitBreaker: $circuitBreaker, sleeper: $sleeper);

        $response = $service->chat(self::STORE_ID, $this->messages);

        self::assertSame('ok', $response->text);
        self::assertFalse($response->usedFallback);
    }

    public function testTransientPrimaryFailureRetriesAndEventuallySucceeds(): void
    {
        $callCount = 0;
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::exactly(3))
            ->method('chat')
            ->willReturnCallback(function () use (&$callCount): ChatResponse {
                $callCount++;
                if ($callCount < 3) {
                    throw $this->timeoutException();
                }

                return $this->response('recovered', 'openai');
            });

        $sleeper = $this->createMock(BackoffSleeperInterface::class);
        $sleeper->expects(self::exactly(2))->method('sleepMilliseconds');

        $service = $this->service($this->providerResolver($primaryProvider, null), sleeper: $sleeper);

        $response = $service->chat(self::STORE_ID, $this->messages);

        self::assertSame('recovered', $response->text);
        self::assertFalse($response->usedFallback);
    }

    public function testExhaustedRetriesFallOverToFallbackProvider(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::exactly(3))->method('chat')->willThrowException($this->timeoutException());

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())->method('chat')->willReturn($this->response('from fallback', 'openai_compatible'));

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::once())
            ->method('recordFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 3, 60);
        $circuitBreaker->expects(self::once())
            ->method('recordSuccess')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK);

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $response = $service->chat(self::STORE_ID, $this->messages);

        self::assertSame('from fallback', $response->text);
        self::assertTrue($response->usedFallback);
    }

    /**
     * Authentication is deliberately never fallback-eligible (a bad
     * primary key must never itself trigger a fallback attempt — see
     * this class's own docblock), and that still holds: the fallback
     * provider is never called and the original exception still
     * propagates unchanged. But since Task 46, recordHardFailure() IS
     * still called — forcing the circuit open on this single occurrence
     * — because the circuit breaker is also ChatWidget's only health
     * signal (Task 44) and an invalid key must be visible there, even
     * though, as a safety boundary, it must never cause a fallback
     * provider to be consulted.
     */
    public function testAuthenticationFailureNeverConsultsFallbackButStillForcesTheCircuitOpenImmediately(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::once())
            ->method('chat')
            ->willThrowException(new ProviderAuthenticationException(new Phrase('bad key')));

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::never())->method('chat');

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::once())
            ->method('recordHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 60);
        $circuitBreaker->expects(self::never())->method('recordFailure');
        $circuitBreaker->expects(self::never())->method('recordSuccess');

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderAuthenticationException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    public function testRateLimitFailureSkipsTheLocalRetryLoopButRemainsFallbackEligible(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::once())
            ->method('chat')
            ->willThrowException(new ProviderRateLimitException(new Phrase('rate limited')));

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())->method('chat')->willReturn($this->response('from fallback', 'openai_compatible'));

        $sleeper = $this->createMock(BackoffSleeperInterface::class);
        $sleeper->expects(self::never())->method('sleepMilliseconds');

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::once())
            ->method('recordHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 60);
        $circuitBreaker->expects(self::never())->method('recordFailure');

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker,
            sleeper: $sleeper
        );

        $response = $service->chat(self::STORE_ID, $this->messages);

        self::assertTrue($response->usedFallback);
    }

    public function testFallbackProviderRateLimitAlsoForcesTheFallbackCircuitOpenImmediately(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())
            ->method('chat')
            ->willThrowException(new ProviderRateLimitException(new Phrase('fallback also rate limited')));

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::once())
            ->method('recordFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 3, 60);
        $circuitBreaker->expects(self::once())
            ->method('recordHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, 60);

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderRateLimitException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    public function testBothPrimaryAndFallbackFailingPropagatesTheFallbackFailure(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->method('chat')->willThrowException(
            new ProviderUnavailableException(new Phrase('fallback also down'))
        );

        $service = $this->service($this->providerResolver($primaryProvider, $fallbackProvider));

        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('fallback also down');
        $service->chat(self::STORE_ID, $this->messages);
    }

    public function testNoFallbackConfiguredPropagatesThePrimaryFailure(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $service = $this->service($this->providerResolver($primaryProvider, null, fallbackEnabled: false));

        $this->expectException(ProviderTimeoutException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    /**
     * Task 44 regression test: closes the one real integration gap left
     * between this class (already proven above to correctly propagate,
     * never swallow, a primary failure when fallback isn't configured —
     * see testNoFallbackConfiguredPropagatesThePrimaryFailure) and
     * ToolCallingChatService, the thin wrapper every real caller
     * (ChatEntryPipeline, the Admin Playground) actually goes through.
     * Live investigation for this task found no reproducible bug in the
     * current code — every real path already produces a proper
     * SafeResponse — but this specific layer combination (a REAL
     * FallbackChatGenerationService, with fallback genuinely disabled,
     * wired into a REAL, un-mocked ToolCallingChatService) had no direct
     * test of its own before now, so this locks it in.
     */
    public function testConverseNeverSwallowsAPrimaryFailurePropagatedFromFallbackChatGenerationServiceWhenFallbackIsDisabled(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $fallbackDisabledService = $this->service(
            $this->providerResolver($primaryProvider, null, fallbackEnabled: false)
        );

        $toolRegistry = $this->createMock(CommerceToolRegistryInterface::class);
        $toolRegistry->method('all')->willReturn([]);

        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('maxToolCalls')->willReturn(4);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        $toolCallingChatService = new ToolCallingChatService($fallbackDisabledService, $toolRegistry, $configReader);

        $this->expectException(ProviderTimeoutException::class);
        $toolCallingChatService->converse(self::STORE_ID, null, null, $this->messages, null);
    }

    public function testCircuitBreakerOpenSkipsPrimaryEntirely(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::never())->method('chat');

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())->method('chat')->willReturn($this->response('from fallback', 'openai_compatible'));

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, true],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, false],
        ]);

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $response = $service->chat(self::STORE_ID, $this->messages);

        self::assertTrue($response->usedFallback);
    }

    /**
     * Task 46's alternating-message fix. Reproduces the real bug found
     * live: with the primary circuit already open from an earlier hard
     * failure, a call made during the cooldown never re-attempts the
     * primary (isOpen() is checked first), so $primaryException stays
     * null — before this fix, attemptFallback()'s "nothing left to try"
     * branch always synthesized a generic ProviderUnavailableException
     * there, which HardFailureClassifier does not treat as hard,
     * silently downgrading the customer-facing message from
     * assistant_down back to assistant_unavailable for every request
     * made during the cooldown. wasOpenedByHardFailure() lets this call
     * recover that context even though it never saw a fresh exception.
     */
    public function testPrimaryCircuitAlreadyOpenFromAHardFailureThrowsConfirmedDownNotGenericUnavailable(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::never())->method('chat');

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(true);
        $circuitBreaker->method('wasOpenedByHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY)
            ->willReturn(true);

        $service = $this->service(
            $this->providerResolver($primaryProvider, null, fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderConfirmedDownException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    /**
     * The mirror case: the primary circuit is open from ordinary
     * accumulated transient failures (recordFailure(), never
     * recordHardFailure()) — wasOpenedByHardFailure() correctly reports
     * false, and the skip-path keeps throwing the original, generic
     * ProviderUnavailableException exactly as it always did. Proves the
     * fix above is narrowly scoped to hard opens, not every open circuit.
     */
    public function testPrimaryCircuitAlreadyOpenFromASoftFailureStillThrowsGenericUnavailable(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::never())->method('chat');

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(true);
        $circuitBreaker->method('wasOpenedByHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY)
            ->willReturn(false);

        $service = $this->service(
            $this->providerResolver($primaryProvider, null, fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderUnavailableException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    /**
     * Task 47's "hide doesn't survive a refresh" fix. Live investigation
     * found the real gap: with fallback enabled and its OWN circuit
     * still closed (not yet individually tripped past its multi-failure
     * threshold), a customer refresh saw the widget reappear even
     * though EVERY real request during that window was failing on both
     * primary (already hard-down) and fallback (a real, repeated
     * failure just below its own threshold) — because
     * ChatWidget::isAssistantConfirmedDown() only reads FALLBACK's
     * circuit state, and nothing had force-opened it yet. Reproduces
     * the skip-path variant: primary's circuit is ALREADY open from an
     * earlier hard failure ($primaryException stays null — see chat()'s
     * own control flow), fallback IS attempted (its circuit still
     * closed) and fails with an ordinary SOFT exception. Proves the
     * fallback failure is upgraded to hard (forcing FALLBACK's circuit
     * open on this one occurrence too) and the exception thrown is
     * ProviderConfirmedDownException, not the raw soft one — keeping
     * the customer-facing reason code and the widget's next render
     * consistent with the real, already-confirmed-broken state.
     */
    public function testFallbackFailureWhilePrimaryCircuitAlreadyHardOpenUpgradesToConfirmedDown(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::never())->method('chat');

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())
            ->method('chat')
            ->willThrowException($this->timeoutException());

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, true],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, false],
        ]);
        $circuitBreaker->method('wasOpenedByHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY)
            ->willReturn(true);
        $circuitBreaker->expects(self::once())
            ->method('recordHardFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, 60);
        $circuitBreaker->expects(self::never())->method('recordFailure');

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderConfirmedDownException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    /**
     * The non-skip-path variant of the same fix: primary IS attempted
     * this call and fails hard (a real ProviderRateLimitException, not
     * an already-open circuit), fallback is then attempted and fails
     * with an ordinary soft exception. Same upgrade should occur.
     * Deliberately uses rate limit rather than authentication here —
     * authentication is never fallback-eligible at all (a separate,
     * pre-existing safety boundary), so it would never even reach
     * attemptFallback() to exercise this fix in the first place.
     */
    public function testFallbackFailureAfterAFreshHardPrimaryFailureInTheSameCallAlsoUpgradesToConfirmedDown(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->expects(self::once())
            ->method('chat')
            ->willThrowException(new ProviderRateLimitException(new Phrase('rate limited')));

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::once())
            ->method('chat')
            ->willThrowException($this->timeoutException());

        $recordedHardFailures = [];
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::exactly(2))
            ->method('recordHardFailure')
            ->willReturnCallback(function (int $storeId, string $role, int $cooldown) use (&$recordedHardFailures): void {
                $recordedHardFailures[] = [$storeId, $role, $cooldown];
            });
        $circuitBreaker->expects(self::never())->method('recordFailure');

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        try {
            $service->chat(self::STORE_ID, $this->messages);
            self::fail('Expected a ProviderConfirmedDownException.');
        } catch (ProviderConfirmedDownException) {
            // Expected — assert the recorded hard failures below.
        }

        self::assertSame([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 60],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, 60],
        ], $recordedHardFailures);
    }

    public function testCircuitBreakerOpenForFallbackSkipsFallbackAttemptAndPropagatesPrimaryFailure(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $fallbackProvider = $this->createMock(LlmProviderInterface::class);
        $fallbackProvider->expects(self::never())->method('chat');

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, false],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, true],
        ]);

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        $this->expectException(ProviderTimeoutException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    public function testMisconfiguredFallbackResolutionFailureIsTreatedAsNoFallback(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException($this->timeoutException());

        $providerResolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $providerResolver->method('primaryLlmProvider')->willReturn($primaryProvider);
        $providerResolver->method('fallbackLlmProvider')->willThrowException(
            new ProviderConfigurationException(new Phrase('misconfigured'))
        );

        $service = $this->service($providerResolver);

        $this->expectException(ProviderTimeoutException::class);
        $service->chat(self::STORE_ID, $this->messages);
    }

    private function response(string $text, string $provider): ChatResponse
    {
        return new ChatResponse($text, [], new TokenUsage(1, 1), $provider, 'test-model', 5);
    }

    private function timeoutException(): ProviderTimeoutException
    {
        return new ProviderTimeoutException(new Phrase('timed out'));
    }

    private function providerResolver(
        LlmProviderInterface $primaryProvider,
        ?LlmProviderInterface $fallbackProvider,
        bool $fallbackEnabled = true
    ): ConfiguredProviderResolverInterface {
        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('primaryLlmProvider')->with(self::STORE_ID)->willReturn($primaryProvider);
        $resolver->method('fallbackLlmProvider')->with(self::STORE_ID)->willReturn(
            $fallbackEnabled ? $fallbackProvider : null
        );

        return $resolver;
    }

    private function service(
        ConfiguredProviderResolverInterface $providerResolver,
        ?CircuitBreakerInterface $circuitBreaker = null,
        ?BackoffSleeperInterface $sleeper = null
    ): FallbackChatGenerationService {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->with(self::STORE_ID)->willReturn($this->createMock(StoreScopeInterface::class));

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('model')->willReturn('primary-model');
        $llmConfig->method('baseUrl')->willReturn('');
        $llmConfig->method('timeoutSeconds')->willReturn(20);
        $llmConfig->method('maxOutputTokens')->willReturn(1200);

        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('isEnabled')->willReturn(true);
        $fallbackConfig->method('model')->willReturn('fallback-model');
        $fallbackConfig->method('baseUrl')->willReturn('');
        $fallbackConfig->method('timeoutSeconds')->willReturn(20);
        $fallbackConfig->method('failureThreshold')->willReturn(3);
        $fallbackConfig->method('cooldownSeconds')->willReturn(60);

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);
        $configReader->method('readFallback')->with(self::STORE_ID)->willReturn($fallbackConfig);

        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getPrimaryLlmApiKey')->willReturn(new SecretValue('primary-key'));
        $secretReader->method('getFallbackLlmApiKey')->willReturn(new SecretValue('fallback-key'));

        $primaryService = new ChatGenerationService($storeScope, $configReader, $providerResolver, $secretReader);

        $circuitBreaker ??= $this->defaultClosedCircuitBreaker();
        $sleeper ??= $this->createMock(BackoffSleeperInterface::class);

        return new FallbackChatGenerationService(
            $primaryService,
            $configReader,
            $providerResolver,
            $secretReader,
            new FallbackEligibilityPolicy(),
            new HardFailureClassifier(),
            $circuitBreaker,
            $sleeper
        );
    }

    private function defaultClosedCircuitBreaker(): CircuitBreakerInterface
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);

        return $circuitBreaker;
    }
}
