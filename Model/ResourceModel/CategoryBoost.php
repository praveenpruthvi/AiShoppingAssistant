<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Standard Magento AbstractDb resource model — used only by the admin
 * grid's ORM stack (Model\CategoryBoost, its Collection, and
 * CategoryBoostRepository). The ranking pipeline's own read path
 * (ActiveCategoryBoostReader) intentionally bypasses this in favor of one
 * lean, scoped raw query, mirroring MerchandisingBoost/ActiveBoostReader's
 * own "no ORM in the runtime hot path" convention; both still point at
 * the exact same table, defined once here as TABLE.
 */
class CategoryBoost extends AbstractDb
{
    public const TABLE = 'aavirbhava_ai_category_boost';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'boost_id');
    }
}
