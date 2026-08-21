<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * ResourceConnection-direct persistence for the attribute_code =>
 * is_indexed selection, mirroring DbCostUsageTracker/DbRebuildFence's own
 * style: a simple keyed table, no AbstractModel/AbstractDb/Collection
 * ORM pair needed for this shape. Deliberately the ONE place either
 * admin entry point or the indexing pipeline ever touches this table —
 * see the interface's own docblock.
 */
final class AttributeIndexingSelectionRepository implements AttributeIndexingSelectionRepositoryInterface
{
    private const TABLE = 'aavirbhava_ai_attribute_indexing_selection';
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock
    ) {
    }

    public function all(): array
    {
        return $this->wrap(function (AdapterInterface $connection): array {
            $rows = $connection->fetchPairs(
                $connection->select()->from($this->table(), ['attribute_code', 'is_indexed'])
            );

            $result = [];
            foreach ($rows as $code => $isIndexed) {
                $result[(string) $code] = (bool) $isIndexed;
            }

            return $result;
        });
    }

    public function indexedCodes(): array
    {
        $codes = [];
        foreach ($this->all() as $code => $isIndexed) {
            if ($isIndexed) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $codes;
    }

    public function isIndexed(string $attributeCode): bool
    {
        return $this->wrap(function (AdapterInterface $connection) use ($attributeCode): bool {
            $value = $connection->fetchOne(
                $connection->select()
                    ->from($this->table(), ['is_indexed'])
                    ->where('attribute_code = ?', $attributeCode)
                    ->limit(1)
            );

            return $value !== false && (bool) $value;
        });
    }

    public function setIndexed(array $attributeCodes, bool $isIndexed): void
    {
        $codes = $this->normalizeCodes($attributeCodes);

        if ($codes === []) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->wrap(function (AdapterInterface $connection) use ($codes, $isIndexed, $now): void {
            foreach ($codes as $code) {
                $connection->insertOnDuplicate(
                    $this->table(),
                    [
                        'attribute_code' => $code,
                        'is_indexed' => $isIndexed ? 1 : 0,
                        'updated_at' => $now,
                    ],
                    ['is_indexed', 'updated_at']
                );
            }
        });
    }

    /**
     * @param list<string> $attributeCodes
     *
     * @return list<string>
     */
    private function normalizeCodes(array $attributeCodes): array
    {
        $codes = [];

        foreach ($attributeCodes as $code) {
            if (!is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1) {
                throw new CatalogException(__('An attribute indexing selection contains an invalid attribute code.'));
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * @template T
     * @param callable(AdapterInterface): T $callback
     * @return T
     */
    private function wrap(callable $callback): mixed
    {
        try {
            return $callback($this->resource->getConnection());
        } catch (CatalogException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new CatalogException(
                __('The attribute indexing selection could not be read or saved.'),
                $throwable instanceof \Exception ? $throwable : null
            );
        }
    }
}
