<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\ProviderCost;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\Provider;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the add/edit form and review grid for the dynamic, per-provider
 * cost admin screen. The provider dropdown is `Model\Config\Source\Provider`
 * itself — the exact same source model the Primary/Fallback LLM system.xml
 * fields already use — so a newly-registered LLM provider appears here
 * automatically, with no separate provider list to keep in sync.
 */
class Index extends Template
{
    /**
     * @var array<string, array{input: float, output: float}>|null
     */
    private ?array $allPrices = null;

    public function __construct(
        Context $context,
        private readonly ProviderCostRepositoryInterface $repository,
        private readonly Provider $providerSource,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getProviderOptions(): array
    {
        return $this->providerSource->toOptionArray();
    }

    /**
     * @return list<array{identifier: string, label: string, input: float, output: float}>
     */
    public function getConfiguredProviders(): array
    {
        $labels = $this->providerLabels();

        $rows = [];
        foreach ($this->allPrices() as $identifier => $prices) {
            $rows[] = [
                'identifier' => $identifier,
                'label' => $labels[$identifier] ?? $identifier,
                'input' => $prices['input'],
                'output' => $prices['output'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $rows;
    }

    /**
     * The provider identifier the form should pre-fill for editing, from
     * a `?provider=` redirect after a save or a review-grid "Edit" link.
     * Empty when adding/editing nothing yet.
     */
    public function getEditingProviderIdentifier(): string
    {
        $identifier = (string) $this->getRequest()->getParam('provider', '');

        foreach ($this->getProviderOptions() as $option) {
            if ($option['value'] === $identifier) {
                return $identifier;
            }
        }

        return '';
    }

    public function getEditingInputPrice(): float
    {
        $identifier = $this->getEditingProviderIdentifier();
        if ($identifier === '') {
            return 0.0;
        }

        return $this->allPrices()[$identifier]['input'] ?? 0.0;
    }

    public function getEditingOutputPrice(): float
    {
        $identifier = $this->getEditingProviderIdentifier();
        if ($identifier === '') {
            return 0.0;
        }

        return $this->allPrices()[$identifier]['output'] ?? 0.0;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('aavirbhava_aishoppingassistant/providercost/save');
    }

    public function getEditUrl(string $providerIdentifier): string
    {
        return $this->getUrl('*/*/index', ['provider' => $providerIdentifier]);
    }

    /**
     * Notices for whichever of the Primary/Fallback LLM providers is
     * currently selected and still has no real cost configured (still
     * 0.0, whether because no row exists at all or because a row exists
     * with an explicit 0.0) — so a merchant isn't silently under-tracking
     * spend against the cost cap without knowing it.
     *
     * @return list<string>
     */
    public function getUnconfiguredProviderNotices(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $providerCost = $this->configurationReader->readProviderCost($storeId);
        $labels = $this->providerLabels();

        $notices = [];

        $primary = $this->configurationReader->readLlm($storeId)->provider();
        if ($this->isUnpriced($providerCost, $primary)) {
            $notices[] = (string) __(
                'The currently-selected Primary LLM provider ("%1") has no cost configured yet '
                . '— its usage is tracked as $0.00 against the cost cap until pricing is added below.',
                $labels[$primary] ?? $primary
            );
        }

        $fallback = $this->configurationReader->readFallback($storeId);
        if ($fallback->isEnabled()
            && $fallback->provider() !== $primary
            && $this->isUnpriced($providerCost, $fallback->provider())
        ) {
            $notices[] = (string) __(
                'The currently-selected Fallback LLM provider ("%1") has no cost configured yet '
                . '— its usage is tracked as $0.00 against the cost cap until pricing is added below.',
                $labels[$fallback->provider()] ?? $fallback->provider()
            );
        }

        return $notices;
    }

    private function isUnpriced(ProviderCostConfigInterface $providerCost, string $identifier): bool
    {
        return $providerCost->pricePerThousandInputTokens($identifier) === 0.0
            && $providerCost->pricePerThousandOutputTokens($identifier) === 0.0;
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    private function allPrices(): array
    {
        if ($this->allPrices === null) {
            $this->allPrices = $this->repository->all();
        }

        return $this->allPrices;
    }

    /**
     * @return array<string, string>
     */
    private function providerLabels(): array
    {
        $labels = [];
        foreach ($this->getProviderOptions() as $option) {
            $labels[$option['value']] = $option['label'];
        }

        return $labels;
    }
}
