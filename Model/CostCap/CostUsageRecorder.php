<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapNotifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Called once per real completed LLM call (see Model\Chat\
 * CostTrackingChatGenerationService — the single seam every real chat()
 * call in this module flows through, including both the main pipeline's
 * tool-call rounds and the Admin Playground's query runner). Records the
 * real usage, then checks whether this increment newly crossed the
 * warning threshold and/or the cap itself, sending at most one email per
 * threshold per period via CostUsageTrackerInterface::
 * claimThresholdNotification()'s compare-and-swap dedup.
 *
 * Every step is wrapped in one top-level try/catch: a tracking or
 * notification failure is logged and swallowed, never allowed to turn a
 * successful chat response into a failed one — the same fail-open
 * discipline CostCapEnforcer applies to the render-time check, applied
 * here to the write side.
 */
final class CostUsageRecorder
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CostUsageTrackerInterface $tracker,
        private readonly PeriodCalculator $periodCalculator,
        private readonly CostCalculator $costCalculator,
        private readonly ClockInterface $clock,
        private readonly CostCapNotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(int $storeId, string $providerIdentifier, TokenUsage $usage): void
    {
        try {
            $costCapConfig = $this->configurationReader->readCostCap($storeId);
            $providerCostConfig = $this->configurationReader->readProviderCost($storeId);
            $cost = $this->costCalculator->cost($usage, $providerIdentifier, $providerCostConfig);
            $periodKey = $this->periodCalculator->periodKey($costCapConfig->period(), $this->clock->now());

            $this->tracker->recordUsage(
                $periodKey,
                $costCapConfig->period(),
                $usage->inputTokens,
                $usage->outputTokens,
                $cost
            );

            if ($costCapConfig->capAmount() <= 0.0) {
                return;
            }

            $this->maybeNotify($storeId, $costCapConfig, $periodKey);
        } catch (Throwable $exception) {
            $this->logger->error('AI shopping assistant: cost usage tracking failed.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function maybeNotify(int $storeId, CostCapConfigInterface $config, string $periodKey): void
    {
        $current = $this->tracker->currentUsage($periodKey);
        $warningAmount = $config->capAmount() * ($config->warningThresholdPercent() / 100);

        if ($current->costAmount() >= $warningAmount
            && $this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::WARNING)
        ) {
            $this->notifier->notify($storeId, $config, $current, $periodKey, CostCapThreshold::WARNING);
        }

        if ($current->costAmount() >= $config->capAmount()
            && $this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::CAP)
        ) {
            $this->notifier->notify($storeId, $config, $current, $periodKey, CostCapThreshold::CAP);
        }
    }
}
