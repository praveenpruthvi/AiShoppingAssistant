<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost;

use Aavirbhava\AiShoppingAssistant\Model\CategoryBoost as CategoryBoostModel;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost as CategoryBoostResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Backs the standalone category boost grid's Ui Component DataProvider.
 * The only consumer of this class is CategoryBoostGrid\DataProvider — the
 * ranking pipeline's own read path never uses it (see
 * ActiveCategoryBoostReader).
 *
 * Deliberately does NOT join a category name here the way
 * MerchandisingBoost\Collection joins the product's own SKU: a category's
 * display name is EAV-stored (no fixed, non-EAV column on
 * catalog_category_entity carries it, unlike a product's plain `sku`
 * column), so a cheap SQL join isn't available — CategoryBoostGrid\
 * DataProvider resolves names separately, in PHP, via a real category
 * collection scoped to only the ids on the current grid page.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'boost_id';

    protected function _construct(): void
    {
        $this->_init(CategoryBoostModel::class, CategoryBoostResource::class);
    }
}
