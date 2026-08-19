<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Diagnostics;

/**
 * One store's index-coverage result (Console\Command\IndexCoverageCommand,
 * Task 23): the real catalog SKU count, the real indexed SKU count (or null
 * when this store has no assistant index alias yet), and the two-way SKU
 * diff between them.
 */
final readonly class IndexCoverageReport
{
    /**
     * @param list<string> $missingFromIndex catalog SKUs absent from the index
     * @param list<string> $missingFromCatalog indexed SKUs absent from the catalog
     */
    public function __construct(
        public int $storeId,
        public string $storeCode,
        public int $catalogCount,
        public ?int $indexCount,
        public array $missingFromIndex,
        public array $missingFromCatalog
    ) {
    }

    public function indexAvailable(): bool
    {
        return $this->indexCount !== null;
    }

    public function isFullyCovered(): bool
    {
        return $this->indexAvailable() && $this->missingFromIndex === [] && $this->missingFromCatalog === [];
    }
}
