<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCapThreshold;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageSnapshot;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\EmailCostCapNotifier;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailCostCapNotifier::class)]
final class EmailCostCapNotifierTest extends TestCase
{
    private const STORE_ID = 3;

    public function testSendsToEveryConfiguredRecipientWithRealUsageFacts(): void
    {
        $config = $this->config(50.0, 'daily', true, ['ops@store.test', 'finance@store.test']);
        $usage = new CostUsageSnapshot(true, 55.0, CostCapThreshold::CAP);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::once())
            ->method('setTemplateIdentifier')
            ->with('aavirbhava_cost_cap_alert_email_template')
            ->willReturnSelf();
        $transportBuilder->expects(self::once())
            ->method('setTemplateOptions')
            ->with(['area' => Area::AREA_ADMINHTML, 'store' => self::STORE_ID])
            ->willReturnSelf();
        $transportBuilder->expects(self::once())
            ->method('setTemplateVars')
            ->willReturnCallback(function (array $vars) use ($transportBuilder) {
                self::assertSame('55.00', $vars['current_cost']);
                self::assertSame('50.00', $vars['cap_amount']);
                self::assertSame('2026-08-21', $vars['period_key']);
                // Live-caught bug (real Mailcatcher capture): the email
                // template's {{var}} directive silently renders a Phrase
                // object as empty, unlike a plain string, even though
                // Phrase itself implements __toString() — every translated
                // var must be cast to a real string before reaching
                // setTemplateVars(), not passed as the raw __() result.
                self::assertIsString($vars['threshold_label']);
                self::assertSame('Cap Reached', $vars['threshold_label']);
                self::assertIsString($vars['override_status']);

                return $transportBuilder;
            });
        $transportBuilder->expects(self::once())->method('setFrom')->with('general')->willReturnSelf();
        $transportBuilder->expects(self::once())
            ->method('addTo')
            ->with(['ops@store.test', 'finance@store.test'])
            ->willReturnSelf();
        $transportBuilder->expects(self::once())->method('getTransport')->willReturn($transport);

        $inlineTranslation = $this->createMock(StateInterface::class);
        $inlineTranslation->expects(self::once())->method('suspend');
        $inlineTranslation->expects(self::once())->method('resume');

        $notifier = new EmailCostCapNotifier($transportBuilder, $inlineTranslation);

        $notifier->notify(self::STORE_ID, $config, $usage, '2026-08-21', CostCapThreshold::CAP);
    }

    public function testSendsNothingWhenNoRecipientsAreConfigured(): void
    {
        $config = $this->config(50.0, 'daily', true, []);
        $usage = new CostUsageSnapshot(true, 55.0, CostCapThreshold::CAP);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::never())->method('setTemplateIdentifier');

        $notifier = new EmailCostCapNotifier($transportBuilder, $this->createMock(StateInterface::class));

        $notifier->notify(self::STORE_ID, $config, $usage, '2026-08-21', CostCapThreshold::CAP);
    }

    public function testResumesInlineTranslationEvenIfSendingThrows(): void
    {
        $config = $this->config(50.0, 'daily', true, ['ops@store.test']);
        $usage = new CostUsageSnapshot(true, 55.0, CostCapThreshold::CAP);

        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $transportBuilder->method('setTemplateVars')->willReturnSelf();
        $transportBuilder->method('setFrom')->willReturnSelf();
        $transportBuilder->method('addTo')->willReturnSelf();
        $transportBuilder->method('getTransport')->willThrowException(new \RuntimeException('smtp down'));

        $inlineTranslation = $this->createMock(StateInterface::class);
        $inlineTranslation->expects(self::once())->method('suspend');
        $inlineTranslation->expects(self::once())->method('resume');

        $notifier = new EmailCostCapNotifier($transportBuilder, $inlineTranslation);

        $this->expectException(\RuntimeException::class);
        $notifier->notify(self::STORE_ID, $config, $usage, '2026-08-21', CostCapThreshold::CAP);
    }

    /**
     * @param list<string> $emails
     */
    private function config(float $capAmount, string $period, bool $allowOverride, array $emails): CostCapConfigInterface
    {
        $config = $this->createMock(CostCapConfigInterface::class);
        $config->method('capAmount')->willReturn($capAmount);
        $config->method('period')->willReturn($period);
        $config->method('allowOverride')->willReturn($allowOverride);
        $config->method('notificationEmails')->willReturn($emails);

        return $config;
    }
}
