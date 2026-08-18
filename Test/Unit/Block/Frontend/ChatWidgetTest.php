<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Frontend;

use Aavirbhava\AiShoppingAssistant\Api\Config\AppearanceConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
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
        ?UrlInterface $urlBuilder = null
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

        return $objectManager->getObject(ChatWidget::class, [
            'context' => $context,
            'configurationReader' => $configurationReader,
            'storeManager' => $storeManager,
            'moduleManager' => $moduleManager,
        ]);
    }
}
