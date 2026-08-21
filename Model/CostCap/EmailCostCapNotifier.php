<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapNotifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageSnapshotInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;

/**
 * Sends the cost-cap threshold-crossing notification via Magento's native
 * mail/template system, mirroring Magento_Contact\Model\Mail's own
 * TransportBuilder usage (the simplest real core precedent for a
 * single-purpose notification email) — this is the first email this
 * module has ever sent, so no prior local convention existed to follow
 * instead.
 *
 * Performs no deduplication itself and no error handling of its own — a
 * caller must only invoke notify() after winning the compare-and-swap
 * claim in CostUsageTrackerInterface::claimThresholdNotification(), and
 * CostUsageRecorder's own top-level try/catch is what makes a transport
 * failure here fail open rather than break the customer-facing chat
 * response that triggered it.
 */
final class EmailCostCapNotifier implements CostCapNotifierInterface
{
    private const TEMPLATE_IDENTIFIER = 'aavirbhava_cost_cap_alert_email_template';
    private const SENDER_IDENTITY = 'general';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation
    ) {
    }

    public function notify(
        int $storeId,
        CostCapConfigInterface $config,
        CostUsageSnapshotInterface $usage,
        string $periodKey,
        int $thresholdRank
    ): void {
        $recipients = $config->notificationEmails();

        if ($recipients === []) {
            return;
        }

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::TEMPLATE_IDENTIFIER)
                ->setTemplateOptions(['area' => Area::AREA_ADMINHTML, 'store' => $storeId])
                ->setTemplateVars([
                    // Cast every translated Phrase to a plain string —
                    // live-verified via a real captured email (Mailcatcher)
                    // that the {{var}} template directive silently renders
                    // a Phrase object as empty, unlike a plain string, even
                    // though Phrase itself implements __toString().
                    'threshold_label' => (string) ($thresholdRank === CostCapThreshold::CAP ? __('Cap Reached') : __('Warning Threshold')),
                    'current_cost' => number_format($usage->costAmount(), 2),
                    'cap_amount' => number_format($config->capAmount(), 2),
                    'period_label' => ucfirst($config->period()),
                    'period_key' => $periodKey,
                    'override_status' => (string) ($thresholdRank === CostCapThreshold::CAP
                        ? ($config->allowOverride()
                            ? __('Override is enabled — the chat widget is still serving customers.')
                            : __('Override is disabled — the chat widget has stopped rendering for customers.'))
                        : __('The cap has not been reached yet — the chat widget is unaffected.')),
                ])
                ->setFrom(self::SENDER_IDENTITY)
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
