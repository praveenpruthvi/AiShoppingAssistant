<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapNotifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Chat\CostTrackingChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback\BackoffSleeperInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\FallbackChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCalculator;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageRecorder;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageSnapshot;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FallbackChatGenerationService and CostUsageRecorder are both final
 * (like CostTrackingChatGenerationService itself depends on the former,
 * avoiding a DI cycle the same way it avoids one with the undecorated
 * ChatGenerationService) — PHPUnit cannot createMock() a final class, so
 * real instances are built here with THEIR OWN dependencies mocked
 * instead (FallbackChatGenerationService's construction mirrors
 * FallbackChatGenerationServiceTest's own helper exactly; CostUsageRecorder's
 * real behavior is exercised against a mocked CostUsageTrackerInterface so
 * its calls can be asserted on).
 *
 * Proves this decorator (1) records real usage from the real returned
 * ChatResponse after a successful call, unchanged, (2) never records
 * usage for a call that throws — nothing was actually spent — and lets
 * the exception propagate exactly as the undecorated service would.
 */
#[CoversClass(CostTrackingChatGenerationService::class)]
final class CostTrackingChatGenerationServiceTest extends TestCase
{
    private const STORE_ID = 3;

    public function testRecordsUsageFromASuccessfulResponseAndReturnsItUnchanged(): void
    {
        $response = new ChatResponse('ok', [], new TokenUsage(100, 50), 'openai', 'gpt-5.6-terra', 120);

        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willReturn($response);

        $decorated = $this->fallbackService($primaryProvider);

        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->expects(self::once())
            ->method('recordUsage')
            ->with(self::anything(), self::anything(), 100, 50, self::anything());

        $service = new CostTrackingChatGenerationService($decorated, $this->recorder($tracker));

        $result = $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);

        self::assertSame($response, $result);
    }

    public function testDoesNotRecordUsageAndPropagatesWhenTheDecoratedCallThrows(): void
    {
        $primaryProvider = $this->createMock(LlmProviderInterface::class);
        $primaryProvider->method('chat')->willThrowException(new ProviderTimeoutException(new Phrase('timed out')));

        $decorated = $this->fallbackService($primaryProvider, fallbackEnabled: false);

        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->expects(self::never())->method('recordUsage');

        $service = new CostTrackingChatGenerationService($decorated, $this->recorder($tracker));

        $this->expectException(ProviderTimeoutException::class);

        $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
    }

    private function recorder(CostUsageTrackerInterface $tracker): CostUsageRecorder
    {
        $costCapConfig = $this->createMock(CostCapConfigInterface::class);
        $costCapConfig->method('capAmount')->willReturn(0.0);
        $costCapConfig->method('period')->willReturn('daily');

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCostCap')->willReturn($costCapConfig);
        $configurationReader->method('readProviderCost')->willReturn($this->createMock(ProviderCostConfigInterface::class));

        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(false, 0.0, 0));

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-21 12:00:00'));

        return new CostUsageRecorder(
            $configurationReader,
            $tracker,
            new PeriodCalculator(),
            new CostCalculator(),
            $clock,
            $this->createMock(CostCapNotifierInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function fallbackService(
        LlmProviderInterface $primaryProvider,
        bool $fallbackEnabled = true
    ): FallbackChatGenerationService {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->with(self::STORE_ID)->willReturn($this->createMock(StoreScopeInterface::class));

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('model')->willReturn('primary-model');
        $llmConfig->method('baseUrl')->willReturn('');
        $llmConfig->method('timeoutSeconds')->willReturn(20);
        $llmConfig->method('maxOutputTokens')->willReturn(1200);

        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('isEnabled')->willReturn($fallbackEnabled);
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

        $primaryService = new ChatGenerationService($storeScope, $configReader, $this->providerResolver($primaryProvider), $secretReader);

        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);

        return new FallbackChatGenerationService(
            $primaryService,
            $configReader,
            $this->providerResolver($primaryProvider),
            $secretReader,
            new \Aavirbhava\AiShoppingAssistant\Model\Provider\FallbackEligibilityPolicy(),
            $circuitBreaker,
            $this->createMock(BackoffSleeperInterface::class)
        );
    }

    private function providerResolver(LlmProviderInterface $primaryProvider): ConfiguredProviderResolverInterface
    {
        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('primaryLlmProvider')->with(self::STORE_ID)->willReturn($primaryProvider);
        $resolver->method('fallbackLlmProvider')->willReturn(null);

        return $resolver;
    }
}
