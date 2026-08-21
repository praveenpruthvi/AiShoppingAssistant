<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost;

use Aavirbhava\AiShoppingAssistant\Model\MerchandisingBoost as MerchandisingBoostModel;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost as MerchandisingBoostResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Backs the standalone boost grid's Ui Component DataProvider. The only
 * consumer of this class is BoostGrid\DataProvider — the ranking
 * pipeline's own read path never uses it (see ActiveBoostReader).
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'boost_id';

    protected function _construct(): void
    {
        $this->_init(MerchandisingBoostModel::class, MerchandisingBoostResource::class);
    }

    /**
     * Joins the product's own SKU (a plain fixed column, not an EAV
     * attribute) so the grid shows a real SKU instead of a bare numeric
     * product id — deliberately not joining the product name too, since
     * that is EAV-stored (attribute id varies per install) and would add
     * real complexity for a "nice to have" the task's own scope doesn't
     * require.
     */
    protected function _initSelect(): self
    {
        parent::_initSelect();

        $this->getSelect()->joinLeft(
            ['product' => $this->getTable('catalog_product_entity')],
            'product.entity_id = main_table.product_id',
            ['product_sku' => 'product.sku']
        );

        return $this;
    }
}
