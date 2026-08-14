<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;

final readonly class IndexingConfig implements IndexingConfigInterface
{
    /**
     * @param list<string> $searchableAttributeCodes
     */
    public function __construct(
        private int $batchSize,
        private array $searchableAttributeCodes,
        private bool $includeShortDescription,
        private bool $includeLongDescription,
        private bool $aggregateConfigurableVariants,
        private int $maxAttributeValuesPerProduct
    ) {
        if ($batchSize < 1) {
            throw new ConfigurationException(__('Indexing batch size must be a positive integer.'));
        }

        foreach ($searchableAttributeCodes as $code) {
            if (!is_string($code) || preg_match(SearchableAttribute::CODE_PATTERN, $code) !== 1) {
                throw new ConfigurationException(__('The searchable attribute list contains an invalid attribute code.'));
            }
        }

        if ($maxAttributeValuesPerProduct < 1) {
            throw new ConfigurationException(__('The maximum number of attribute values per product must be a positive integer.'));
        }
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function searchableAttributeCodes(): array
    {
        return $this->searchableAttributeCodes;
    }

    public function includeShortDescription(): bool
    {
        return $this->includeShortDescription;
    }

    public function includeLongDescription(): bool
    {
        return $this->includeLongDescription;
    }

    public function aggregateConfigurableVariants(): bool
    {
        return $this->aggregateConfigurableVariants;
    }

    public function maxAttributeValuesPerProduct(): int
    {
        return $this->maxAttributeValuesPerProduct;
    }
}