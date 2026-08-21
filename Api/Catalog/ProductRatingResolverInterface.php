<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Resolves real product-rating facts from Magento's native review system
 * (Magento_Review) for indexing — never a separate provider or a new
 * sync-on-save trigger, since ratings are not freshness-critical the way
 * price/stock are: they refresh whenever the product is next indexed
 * (full rebuild or the existing incremental/reconciliation paths), not on
 * every review submission.
 */
interface ProductRatingResolverInterface
{
    /**
     * Joins each product's own average rating and review count onto the
     * given, not-yet-loaded collection as raw `rating_summary` (0-100) and
     * `reviews_count` columns — mirrors
     * Magento\Review\Model\ResourceModel\Review\Summary::
     * appendSummaryFieldsToCollection(), the same mechanism Magento's own
     * catalog/search listings use to show star ratings, rather than a
     * hand-written join. Must be called before the collection loads.
     */
    public function appendToCollection(Collection $collection, StoreScopeInterface $scope): void;

    /**
     * The store-wide mean product rating (0-5 scale) across every rated
     * product in the catalogue, computed fresh from Magento's native
     * review data. 0.0 when the store has no reviews at all — the same
     * degenerate value every unrated product's own average already is, so
     * RatingSignal's Bayesian blend stays well-defined either way.
     */
    public function catalogAverage(StoreScopeInterface $scope): float;

    /**
     * Converts Magento's native 0-100 `rating_summary` percentage to the
     * 0-5 star scale this module works in everywhere else. The one place
     * that conversion happens, so callers reading the raw joined column
     * off a collection item (see appendToCollection()) never duplicate it.
     */
    public function percentToStars(float $percent): float;
}
