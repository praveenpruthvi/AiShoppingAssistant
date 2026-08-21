<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * ResourceConnection-direct persistence for per-provider token pricing,
 * mirroring AttributeIndexingSelectionRepository's own style: a simple
 * keyed table, no AbstractModel/AbstractDb/Collection ORM pair needed for
 * this shape. Deliberately the ONE place either the admin cost screen or
 * ConfigurationReader::readProviderCost() ever touches this table.
 */
final class ProviderCostRepository implements ProviderCostRepositoryInterface
{
    private const TABLE = 'aavirbhava_ai_provider_cost';

    private const MAX_PRICE_PER_1K_TOKENS = 1000.0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock
    ) {
    }

    public function all(): array
    {
        return $this->wrap(function (AdapterInterface $connection): array {
            $select = $connection->select()->from(
                $this->table(),
                ['provider_identifier', 'price_per_1k_input_tokens', 'price_per_1k_output_tokens']
            );

            $result = [];
            foreach ($connection->fetchAll($select) as $row) {
                $result[(string) $row['provider_identifier']] = [
                    'input' => (float) $row['price_per_1k_input_tokens'],
                    'output' => (float) $row['price_per_1k_output_tokens'],
                ];
            }

            return $result;
        });
    }

    public function setPrice(
        string $providerIdentifier,
        float $pricePerThousandInputTokens,
        float $pricePerThousandOutputTokens
    ): void {
        if (!ProviderIdentifiers::isValid($providerIdentifier)) {
            throw new ConfigurationException(__('A provider identifier is not valid.'));
        }

        $this->assertValidPrice($pricePerThousandInputTokens);
        $this->assertValidPrice($pricePerThousandOutputTokens);

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->wrap(function (AdapterInterface $connection) use (
            $providerIdentifier,
            $pricePerThousandInputTokens,
            $pricePerThousandOutputTokens,
            $now
        ): void {
            $connection->insertOnDuplicate(
                $this->table(),
                [
                    'provider_identifier' => $providerIdentifier,
                    'price_per_1k_input_tokens' => $pricePerThousandInputTokens,
                    'price_per_1k_output_tokens' => $pricePerThousandOutputTokens,
                    'updated_at' => $now,
                ],
                ['price_per_1k_input_tokens', 'price_per_1k_output_tokens', 'updated_at']
            );
        });
    }

    private function assertValidPrice(float $price): void
    {
        if ($price < 0.0 || $price > self::MAX_PRICE_PER_1K_TOKENS) {
            throw new ConfigurationException(
                __('Provider pricing must be between 0 and %1 per 1,000 tokens.', self::MAX_PRICE_PER_1K_TOKENS)
            );
        }
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
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ConfigurationException(
                __('Provider cost pricing could not be read or saved.'),
                $throwable instanceof \Exception ? $throwable : null
            );
        }
    }
}
