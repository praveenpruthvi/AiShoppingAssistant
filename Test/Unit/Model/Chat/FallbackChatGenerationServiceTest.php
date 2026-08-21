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
     * propagates unchanged. But since Task 45, recordFailure IS now
     * still called — with threshold 1, forcing the circuit open on this
     * single occurrence — because the circuit breaker is also
     * ChatWidget's only health signal (Task 44) and an invalid key must
     * be visible there, even though, as a safety boundary, it must never
     * cause a fallback provider to be consulted.
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
            ->method('recordFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 1, 60);
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
            ->method('recordFailure')
            ->with(self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 1, 60);

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

        $recordedFailures = [];
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        $circuitBreaker->expects(self::exactly(2))
            ->method('recordFailure')
            ->willReturnCallback(function (int $storeId, string $role, int $threshold, int $cooldown) use (&$recordedFailures): void {
                $recordedFailures[] = [$storeId, $role, $threshold, $cooldown];
            });

        $service = $this->service(
            $this->providerResolver($primaryProvider, $fallbackProvider),
            circuitBreaker: $circuitBreaker
        );

        try {
            $service->chat(self::STORE_ID, $this->messages);
            self::fail('Expected a ProviderRateLimitException.');
        } catch (ProviderRateLimitException) {
            // Expected — assert the recorded failures below.
        }

        self::assertSame([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, 3, 60],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, 1, 60],
        ], $recordedFailures);
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
