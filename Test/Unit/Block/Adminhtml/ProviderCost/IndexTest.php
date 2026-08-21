<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\ProviderCost;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ProviderLabelRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\ProviderCost\Index;
use Aavirbhava\AiShoppingAssistant\Model\Config\ProviderCostConfig;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\Provider;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the dynamic cost-config screen's data: the provider dropdown
 * really is built from `Model\Config\Source\Provider` (a real instance
 * here, not a stand-in list, since that concrete class is final and
 * can't itself be mocked — the same "use the real deterministic
 * collaborator" precedent ConfigurationReaderTest already established
 * for ColorContrast); the review grid reflects
 * ProviderCostRepositoryInterface::all() exactly; and the "no cost
 * configured" notice fires for whichever of Primary/Fallback is still
 * genuinely priced at 0.0 — whether because no row exists at all or
 * because a row exists with an explicit 0.0 — never for a provider that
 * has real, non-zero pricing.
 */
#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    private const STORE_ID = 1;

    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $type) => $type === Escaper::class ? new Escaper() : $this->createMock($type)
        );
        AppObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(AppObjectManager::class, '_instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    public function testGetProviderOptionsComesFromTheRealSharedProviderSourceModel(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic Claude'],
            configured: [],
            primary: 'openai'
        );

        self::assertSame(
            [
                ['value' => 'anthropic', 'label' => 'Anthropic Claude'],
                ['value' => 'openai', 'label' => 'OpenAI'],
            ],
            $block->getProviderOptions()
        );
    }

    public function testGetConfiguredProvidersReflectsTheRepositorySortedByLabel(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic Claude'],
            configured: [
                'openai' => ['input' => 0.005, 'output' => 0.015],
                'anthropic' => ['input' => 0.003, 'output' => 0.015],
            ],
            primary: 'openai'
        );

        self::assertSame(
            [
                ['identifier' => 'anthropic', 'label' => 'Anthropic Claude', 'input' => 0.003, 'output' => 0.015],
                ['identifier' => 'openai', 'label' => 'OpenAI', 'input' => 0.005, 'output' => 0.015],
            ],
            $block->getConfiguredProviders()
        );
    }

    public function testEditingIdentifierComesFromTheRequestWhenItIsARealProvider(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: ['openai' => ['input' => 0.005, 'output' => 0.015]],
            primary: 'openai',
            requestedProvider: 'openai'
        );

        self::assertSame('openai', $block->getEditingProviderIdentifier());
        self::assertSame(0.005, $block->getEditingInputPrice());
        self::assertSame(0.015, $block->getEditingOutputPrice());
    }

    public function testEditingIdentifierIsEmptyForAProviderNotInTheRealRegistry(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: [],
            primary: 'openai',
            requestedProvider: 'not_a_real_provider'
        );

        self::assertSame('', $block->getEditingProviderIdentifier());
        self::assertSame(0.0, $block->getEditingInputPrice());
        self::assertSame(0.0, $block->getEditingOutputPrice());
    }

    public function testNoticeFiresForThePrimaryProviderWhenItHasNoRowAtAll(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: [],
            primary: 'openai'
        );

        $notices = $block->getUnconfiguredProviderNotices();
        self::assertCount(1, $notices);
        self::assertStringContainsString('OpenAI', $notices[0]);
    }

    public function testNoticeFiresForThePrimaryProviderWhenItHasAnExplicitZeroRow(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: ['openai' => ['input' => 0.0, 'output' => 0.0]],
            primary: 'openai'
        );

        self::assertCount(1, $block->getUnconfiguredProviderNotices());
    }

    public function testNoNoticeWhenThePrimaryProviderHasRealNonZeroPricing(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: ['openai' => ['input' => 0.005, 'output' => 0.015]],
            primary: 'openai'
        );

        self::assertSame([], $block->getUnconfiguredProviderNotices());
    }

    public function testFallbackNoticeFiresIndependentlyOfPrimaryWhenFallbackIsEnabled(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic Claude'],
            configured: ['openai' => ['input' => 0.005, 'output' => 0.015]],
            primary: 'openai',
            fallback: 'anthropic',
            fallbackEnabled: true
        );

        $notices = $block->getUnconfiguredProviderNotices();
        self::assertCount(1, $notices);
        self::assertStringContainsString('Anthropic Claude', $notices[0]);
    }

    public function testFallbackNoticeIsSkippedWhenFallbackIsDisabled(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic Claude'],
            configured: ['openai' => ['input' => 0.005, 'output' => 0.015]],
            primary: 'openai',
            fallback: 'anthropic',
            fallbackEnabled: false
        );

        self::assertSame([], $block->getUnconfiguredProviderNotices());
    }

    public function testFallbackNoticeIsNotDuplicatedWhenFallbackEqualsPrimary(): void
    {
        $block = $this->block(
            labels: ['openai' => 'OpenAI'],
            configured: [],
            primary: 'openai',
            fallback: 'openai',
            fallbackEnabled: true
        );

        self::assertCount(1, $block->getUnconfiguredProviderNotices());
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, array{input: float, output: float}> $configured
     */
    private function block(
        array $labels,
        array $configured,
        string $primary,
        string $fallback = '',
        bool $fallbackEnabled = false,
        string $requestedProvider = ''
    ): Index {
        $objectManager = new ObjectManager($this);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'provider' ? $requestedProvider : $default
        );

        $context = $objectManager->getObject(Context::class, ['escaper' => new Escaper(), 'request' => $request]);

        $repository = $this->createMock(ProviderCostRepositoryInterface::class);
        $repository->method('all')->willReturn($configured);

        $providers = [];
        foreach (array_keys($labels) as $identifier) {
            $providers[$identifier] = $this->createMock(LlmProviderInterface::class);
        }

        $registry = $this->createMock(LlmProviderRegistryInterface::class);
        $registry->method('all')->willReturn($providers);

        $labelRegistry = $this->createMock(ProviderLabelRegistryInterface::class);
        $labelRegistry->method('get')->willReturnCallback(
            static fn (string $identifier): string => $labels[$identifier] ?? $identifier
        );

        $providerSource = new Provider($registry, new ProviderOptionService($labelRegistry));

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('provider')->willReturn($primary);

        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('provider')->willReturn($fallback);
        $fallbackConfig->method('isEnabled')->willReturn($fallbackEnabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readLlm')->willReturn($llmConfig);
        $configurationReader->method('readFallback')->willReturn($fallbackConfig);
        $configurationReader->method('readProviderCost')->willReturn(new ProviderCostConfig($configured));

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $objectManager->getObject(Index::class, [
            'context' => $context,
            'repository' => $repository,
            'providerSource' => $providerSource,
            'configurationReader' => $configurationReader,
            'storeManager' => $storeManager,
        ]);
    }
}
