<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildMetricsInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Framework\Phrase;

final readonly class RebuildMetrics implements RebuildMetricsInterface
{
    /**
     * @param int $storesConsidered active store scopes resolved for this run
     * @param int $storesSkipped stores where the assistant is disabled
     * @param int $storesPrepared stores successfully prepared and finished
     * @param int $productIdsExamined product ids yielded by the batch provider
     * @param int $snapshotsLoaded snapshots returned by the snapshot provider
     * @param int $missingProducts requested ids with no snapshot returned
     * @param int $eligibleDocuments documents written as eligible
     * @param array<string,int> $ineligibleByReason bounded, stable reason codes
     * @param int $batchesWritten document batches acknowledged by the writer
     * @param bool $activated whether the run was promoted to the live index
     * @param float $durationSeconds elapsed rebuild time
     */
    public function __construct(
        private int $storesConsidered,
        private int $storesSkipped,
        private int $storesPrepared,
        private int $productIdsExamined,
        private int $snapshotsLoaded,
        private int $missingProducts,
        private int $eligibleDocuments,
        private array $ineligibleByReason,
        private int $batchesWritten,
        private bool $activated,
        private float $durationSeconds
    ) {
        foreach ([
            'storesConsidered' => $storesConsidered,
            'storesSkipped' => $storesSkipped,
            'storesPrepared' => $storesPrepared,
            'productIdsExamined' => $productIdsExamined,
            'snapshotsLoaded' => $snapshotsLoaded,
            'missingProducts' => $missingProducts,
            'eligibleDocuments' => $eligibleDocuments,
            'batchesWritten' => $batchesWritten,
        ] as $label => $value) {
            if (!is_int($value) || $value < 0) {
                throw new ProductIndexingException(
                    ProductIndexingException::ERROR_INVALID_METRICS,
                    new Phrase('The AI shopping assistant reindex metrics are invalid.'),
                    null
                );
            }
        }

        $knownReasons = [
            ProductEligibilityResultInterface::REASON_INVALID_IDENTITY,
            ProductEligibilityResultInterface::REASON_STORE_MISMATCH,
            ProductEligibilityResultInterface::REASON_WEBSITE_NOT_ASSIGNED,
            ProductEligibilityResultInterface::REASON_DISABLED,
            ProductEligibilityResultInterface::REASON_NOT_SEARCH_VISIBLE,
        ];

        foreach ($ineligibleByReason as $reason => $count) {
            if (!is_string($reason) || !in_array($reason, $knownReasons, true) || !is_int($count) || $count < 1) {
                throw new ProductIndexingException(
                    ProductIndexingException::ERROR_INVALID_METRICS,
                    new Phrase('The AI shopping assistant reindex metrics are invalid.'),
                    null
                );
            }
        }

        if ($durationSeconds < 0) {
            throw new ProductIndexingException(
                ProductIndexingException::ERROR_INVALID_METRICS,
                new Phrase('The AI shopping assistant reindex metrics are invalid.'),
                null
            );
        }
    }

    public function storesConsidered(): int
    {
        return $this->storesConsidered;
    }

    public function storesSkipped(): int
    {
        return $this->storesSkipped;
    }

    public function storesPrepared(): int
    {
        return $this->storesPrepared;
    }

    public function productIdsExamined(): int
    {
        return $this->productIdsExamined;
    }

    public function snapshotsLoaded(): int
    {
        return $this->snapshotsLoaded;
    }

    public function missingProducts(): int
    {
        return $this->missingProducts;
    }

    public function eligibleDocuments(): int
    {
        return $this->eligibleDocuments;
    }

    public function ineligibleByReason(): array
    {
        return $this->ineligibleByReason;
    }

    public function batchesWritten(): int
    {
        return $this->batchesWritten;
    }

    public function activated(): bool
    {
        return $this->activated;
    }

    public function durationSeconds(): float
    {
        return $this->durationSeconds;
    }
}
