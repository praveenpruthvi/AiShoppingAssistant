<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductRatingResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Review\Model\ResourceModel\Review\Summary as ReviewSummaryResource;

/**
 * Reads real product-rating facts from Magento's native review system
 * (Magento_Review) via its own `review_entity_summary` table — never a
 * separate rating provider, never a new sync-on-save trigger.
 *
 * `rating_summary` is stored by Magento as a 0-100 percentage (an average
 * of each review's own percent rating); this class is the one place that
 * scale is converted to the 0-5 stars RatingSignal and the rest of this
 * module work in, via ::percentToStars().
 */
final class ProductRatingResolver implements ProductRatingResolverInterface
{
    private const ENTITY_CODE = 'product';

    public function __construct(
        private readonly ReviewSummaryResource $summaryResource
    ) {
    }

    public function appendToCollection(Collection $collection, StoreScopeInterface $scope): void
    {
        $this->summaryResource->appendSummaryFieldsToCollection($collection, $scope->storeId(), self::ENTITY_CODE);
    }

    public function catalogAverage(StoreScopeInterface $scope): float
    {
        $connection = $this->summaryResource->getConnection();

        $entitySubSelect = $connection->select()
            ->from(['review_entity' => $this->summaryResource->getTable('review_entity')], ['entity_id'])
            ->where('entity_code = ?', self::ENTITY_CODE);

        // Only products that have at least one real review contribute to
        // the catalogue mean — an unrated product must never drag the
        // prior itself toward zero, which would make RatingSignal's
        // Bayesian blend meaningless for every product in the store.
        $select = $connection->select()
            ->from($this->summaryResource->getMainTable(), [new \Zend_Db_Expr('AVG(rating_summary)')])
            ->where('store_id = ?', $scope->storeId())
            ->where('reviews_count > 0')
            ->where("entity_type = ({$entitySubSelect})");

        $averagePercent = $connection->fetchOne($select);

        if ($averagePercent === false || $averagePercent === null) {
            return 0.0;
        }

        return $this->percentToStars((float) $averagePercent);
    }

    public function percentToStars(float $percent): float
    {
        return max(0.0, min(5.0, $percent / 20.0));
    }
}
