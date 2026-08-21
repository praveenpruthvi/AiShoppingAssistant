<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Frontend;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CircuitBreakerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\AppearanceConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapCheckerInterface;
use Aavirbhava\AiShoppingAssistant\Block\Frontend\ChatWidget;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves ChatWidget stays a pure view: it never renders (toHtml() ===
 * '') when general.enabled is false, degrades the same way if reading
 * config throws (never allowed to break page rendering for every
 * storefront visitor), selects the Hyva template purely based on
 * Hyva_Theme module presence, and builds the send URL from
 * Controller\Chat\Send's real route.
 *
 * Only the disabled path is exercised through the real toHtml()/_toHtml()
 * chain here — that branch returns before ever reaching Template's real
 * fetchView()/template-engine machinery, which a bare PHPUnit process
 * (no full Magento app bootstrap) cannot safely exercise. The enabled
 * path's actual template rendering is verified live instead (see the
 * Task 11 status report's Container verification section) — the same
 * "unit-test what's safe to unit-test, live-verify the rest" split this
 * module has used since Task 9's Block test.
 */
#[CoversClass(ChatWidget::class)]
final class ChatWidgetTest extends TestCase
{
    private const STORE_ID = 3;

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

    public function testUsesTheDefaultTemplateWhenHyvaThemeIsNotPresent(): void
    {
        $block = $this->block(hyvaPresent: false);

        self::assertSame('Aavirbhava_AiShoppingAssistant::chat/widget.phtml', $block->getTemplate());
    }

    public function testUsesTheHyvaTemplateWhenHyvaThemeModuleIsPresent(): void
    {
        $block = $this->block(hyvaPresent: true);

        self::assertSame('Aavirbhava_AiShoppingAssistant::chat/widget-hyva.phtml', $block->getTemplate());
    }

    public function testGetSendUrlBuildsTheChatSendRoute(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('aichat/chat/send', [])
            ->willReturn('https://store.test/aichat/chat/send');

        $block = $this->block(urlBuilder: $urlBuilder);

        self::assertSame('https://store.test/aichat/chat/send', $block->getSendUrl());
    }

    public function testGetHistoryUrlBuildsTheChatHistoryRoute(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects(self::once())
            ->method('getUrl')
            ->with('aichat/chat/history', [])
            ->willReturn('https://store.test/aichat/chat/history');

        $block = $this->block(urlBuilder: $urlBuilder);

        self::assertSame('https://store.test/aichat/chat/history', $block->getHistoryUrl());
    }

    public function testToHtmlIsEmptyWhenTheAssistantIsDisabled(): void
    {
        $block = $this->block(assistantEnabled: false);

        self::assertSame('', $block->toHtml());
    }

    public function testToHtmlIsEmptyRatherThanThrowingWhenConfigurationReadingFails(): void
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->willThrowException(new \RuntimeException('boom'));

        $block = $this->block(configurationReader: $configurationReader);

        self::assertSame('', $block->toHtml());
    }

    public function testToHtmlIsEmptyWhenTheCostCapCheckerReportsBlocking(): void
    {
        $costCapChecker = $this->createMock(CostCapCheckerInterface::class);
        $costCapChecker->method('isBlocking')->willReturn(true);

        $block = $this->block(costCapChecker: $costCapChecker);

        self::assertSame('', $block->toHtml());
    }

    /**
     * Task 44: the primary circuit alone being open is not enough to hide
     * the widget — with fallback disabled there is genuinely no working
     * path left, so the widget correctly hides.
     */
    public function testToHtmlIsEmptyWhenPrimaryCircuitIsOpenAndFallbackIsDisabled(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, true],
        ]);

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        self::assertSame('', $block->toHtml());
    }

    /**
     * Primary down AND fallback also confirmed down (its own circuit
     * open) — genuinely no working path, widget correctly hides.
     */
    public function testToHtmlIsEmptyWhenPrimaryAndFallbackCircuitsAreBothOpen(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, true],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, true],
        ]);

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: true),
            circuitBreaker: $circuitBreaker
        );

        self::assertSame('', $block->toHtml());
    }

    /**
     * The case this task's own Part A fix (not this widget check) is
     * responsible for: primary down but a real, enabled, healthy
     * fallback is available. A real chat request in this exact state
     * still gets a real AI response (via fallback), so the widget must
     * keep rendering — hiding it here would be wrong, not merely
     * over-cautious.
     *
     * Asserted against the private isAssistantConfirmedDown() check
     * directly (via reflection) rather than the public toHtml(): a
     * "does not hide" outcome falls through to Template's own real
     * fetchView()/template-engine machinery, which — per this file's own
     * documented convention above — a bare PHPUnit process cannot safely
     * exercise. This still proves exactly the logic this test is about:
     * whether the new safeguard itself decides to hide or not.
     */
    public function testDoesNotConsiderTheAssistantDownWhenPrimaryCircuitIsOpenButFallbackIsEnabledAndHealthy(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, true],
            [self::STORE_ID, CircuitBreakerInterface::ROLE_FALLBACK, false],
        ]);

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: true),
            circuitBreaker: $circuitBreaker
        );

        self::assertFalse($this->isAssistantConfirmedDown($block));
    }

    /**
     * Primary healthy (circuit closed) — not confirmed down regardless
     * of fallback state, since there is nothing to be confirmed down
     * about.
     */
    public function testDoesNotConsiderTheAssistantDownWhenPrimaryIsHealthy(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturnMap([
            [self::STORE_ID, CircuitBreakerInterface::ROLE_PRIMARY, false],
        ]);

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        self::assertFalse($this->isAssistantConfirmedDown($block));
    }

    /**
     * A single failed request never trips the circuit breaker on its
     * own — CircuitBreakerInterface's own contract only opens it once
     * failureThreshold CONSECUTIVE failures accumulate. Reading the
     * circuit's own state (still closed here, representing "one bad
     * call happened but the breaker hasn't tripped") rather than any
     * single request's own outcome is what makes this naturally correct
     * with no extra logic in this class — this test documents that
     * guarantee explicitly rather than leaving it implicit.
     */
    public function testDoesNotConsiderTheAssistantDownAfterASingleTransientFailureThatHasNotTrippedTheCircuit(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willReturn(false);

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        self::assertFalse($this->isAssistantConfirmedDown($block));
    }

    private function isAssistantConfirmedDown(ChatWidget $block): bool
    {
        $method = new \ReflectionMethod(ChatWidget::class, 'isAssistantConfirmedDown');
        $method->setAccessible(true);

        return $method->invoke($block);
    }

    /**
     * Fails CLOSED (hides) on its own internal error — deliberately the
     * opposite direction from the cost-cap check right next to it in
     * _toHtml(), matching isAssistantEnabled()'s own fail-closed
     * precedent in this same class instead.
     */
    public function testToHtmlIsEmptyWhenTheHealthCheckItselfThrows(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $circuitBreaker->method('isOpen')->willThrowException(new \RuntimeException('cache unavailable'));

        $block = $this->block(
            configurationReader: $this->configurationReader(fallbackEnabled: false),
            circuitBreaker: $circuitBreaker
        );

        self::assertSame('', $block->toHtml());
    }

    private function configurationReader(bool $fallbackEnabled): ConfigurationReaderInterface
    {
        $fallback = $this->createMock(FallbackConfigInterface::class);
        $fallback->method('isEnabled')->willReturn($fallbackEnabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->with(self::STORE_ID)->willReturn($this->enabledGeneral());
        $configurationReader->method('readFallback')->with(self::STORE_ID)->willReturn($fallback);

        return $configurationReader;
    }

    public function testColorGettersReturnTheConfiguredValues(): void
    {
        $appearance = $this->createMock(AppearanceConfigInterface::class);
        $appearance->method('primaryColor')->willReturn('#112233');
        $appearance->method('primaryTextColor')->willReturn('#ffffff');
        $appearance->method('messageBubbleColor')->willReturn('#eeeeee');
        $appearance->method('messageTextColor')->willReturn('#222222');

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->willReturn($this->enabledGeneral());
        $configurationReader->method('readAppearance')->with(self::STORE_ID)->willReturn($appearance);

        $block = $this->block(configurationReader: $configurationReader);

        self::assertSame('#112233', $block->getPrimaryColor());
        self::assertSame('#ffffff', $block->getPrimaryTextColor());
        self::assertSame('#eeeeee', $block->getMessageBubbleColor());
        self::assertSame('#222222', $block->getMessageTextColor());
        self::assertSame(
            '--aavirbhava-primary-color:#112233;--aavirbhava-primary-text-color:#ffffff;'
            . '--aavirbhava-message-bg-color:#eeeeee;--aavirbhava-message-text-color:#222222',
            $block->getColorCustomPropertiesStyle()
        );
    }

    public function testColorGettersDegradeToThisModulesOriginalDefaultsRatherThanThrowingWhenAppearanceReadingFails(): void
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->willReturn($this->enabledGeneral());
        $configurationReader->method('readAppearance')->willThrowException(new \RuntimeException('boom'));

        $block = $this->block(configurationReader: $configurationReader);

        self::assertSame('#1979c3', $block->getPrimaryColor());
        self::assertSame('#ffffff', $block->getPrimaryTextColor());
        self::assertSame('#f2f2f2', $block->getMessageBubbleColor());
        self::assertSame('#222222', $block->getMessageTextColor());
        self::assertNotSame('', $block->getColorCustomPropertiesStyle());
    }

    private function enabledGeneral(): GeneralConfigInterface
    {
        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isEnabled')->willReturn(true);

        return $general;
    }

    private function block(
        bool $assistantEnabled = true,
        bool $hyvaPresent = false,
        ?ConfigurationReaderInterface $configurationReader = null,
        ?UrlInterface $urlBuilder = null,
        ?CostCapCheckerInterface $costCapChecker = null,
        ?CircuitBreakerInterface $circuitBreaker = null
    ): ChatWidget {
        $objectManager = new ObjectManager($this);

        if ($configurationReader === null) {
            $general = $this->createMock(GeneralConfigInterface::class);
            $general->method('isEnabled')->willReturn($assistantEnabled);
            $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
            $configurationReader->method('readGeneral')->with(self::STORE_ID)->willReturn($general);
        }

        $urlBuilder ??= $this->createMock(UrlInterface::class);

        $context = $objectManager->getObject(Context::class, [
            'escaper' => new Escaper(),
            'urlBuilder' => $urlBuilder,
        ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->with('Hyva_Theme')->willReturn($hyvaPresent);

        if ($costCapChecker === null) {
            $costCapChecker = $this->createMock(CostCapCheckerInterface::class);
            $costCapChecker->method('isBlocking')->willReturn(false);
        }

        if ($circuitBreaker === null) {
            $circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
            $circuitBreaker->method('isOpen')->willReturn(false);
        }

        return $objectManager->getObject(ChatWidget::class, [
            'context' => $context,
            'configurationReader' => $configurationReader,
            'storeManager' => $storeManager,
            'moduleManager' => $moduleManager,
            'costCapChecker' => $costCapChecker,
            'circuitBreaker' => $circuitBreaker,
        ]);
    }
}
