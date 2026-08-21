<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapCheckerInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The server-side render-time gate ChatWidget consults — a pure read, no
 * usage is recorded here (that happens once per real completed LLM call,
 * in CostUsageRecorder, not once per page render). Fails open by design:
 * any error anywhere in this method (config read, store resolution,
 * tracker read) resolves to "not blocking" rather than propagating, since
 * a tracking failure must never silently take down a working, revenue-
 * relevant customer channel — the opposite fail-safe direction from
 * ChatWidget::isAssistantEnabled(), which fails closed on its own
 * (unrelated) config read.
 */
final class CostCapEnforcer implements CostCapCheckerInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly CostUsageTrackerInterface $tracker,
        private readonly PeriodCalculator $periodCalculator,
        private readonly ClockInterface $clock,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isBlocking(): bool
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $config = $this->configurationReader->readCostCap($storeId);

            if ($config->capAmount() <= 0.0) {
                return false;
            }

            $periodKey = $this->periodCalculator->periodKey($config->period(), $this->clock->now());
            $usage = $this->tracker->currentUsage($periodKey);

            $capReached = $usage->costAmount() >= $config->capAmount();

            return $capReached && !$config->allowOverride();
        } catch (Throwable $exception) {
            $this->logger->error('AI shopping assistant: cost cap check failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
