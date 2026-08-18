<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use InvalidArgumentException;

/**
 * Runs candidates through every registered signal in sequence, then sorts by
 * the accumulated score and caps at the store's configured final_products.
 *
 * Signals are injected as a plain ordered array via di.xml (see
 * etc/di.xml), the same array-construction mechanism
 * LlmProviderRegistry/EmbeddingProviderRegistry use for third-party
 * extensibility — but unlike those registries this is not an
 * identifier-keyed allowlist of one selected implementation: every
 * registered signal always runs, in registration order, so there is no
 * get($identifier)/has($identifier) lookup here. Adding a Phase 2 signal
 * (PromotionSignal, MarginSignal, ...) requires only a new class
 * implementing RankingSignalInterface plus one new di.xml <item> — this
 * class and the four Phase-1 signals never change.
 */
final class RankingPipeline implements RankingPipelineInterface
{
    /**
     * @var array<string, RankingSignalInterface>
     */
    private readonly array $signals;

    /**
     * @param array<string, RankingSignalInterface> $signals
     */
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        array $signals = []
    ) {
        foreach ($signals as $identifier => $signal) {
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException('Ranking signal keys must be non-empty strings.');
            }

            if (!$signal instanceof RankingSignalInterface) {
                throw new InvalidArgumentException('A registered ranking signal does not implement RankingSignalInterface.');
            }
        }

        // Kept keyed (not array_values()'d) so rank() can report each
        // signal's own di.xml identifier to an optional debug collector;
        // iteration order is unaffected either way.
        $this->signals = $signals;
    }

    public function rank(SearchContext $context, array $candidates, ?RankingSignalCollectorInterface $collector = null): array
    {
        foreach ($this->signals as $identifier => $signal) {
            $candidates = $signal->apply($context, $candidates);
            $collector?->recordStage($identifier, $candidates);
        }

        usort(
            $candidates,
            static function (SearchCandidate $a, SearchCandidate $b): int {
                return $b->score <=> $a->score ?: $a->entityId <=> $b->entityId;
            }
        );

        $finalProducts = $this->configurationReader->readRetrieval($context->storeId)->finalProducts();

        return array_slice($candidates, 0, $finalProducts);
    }
}
