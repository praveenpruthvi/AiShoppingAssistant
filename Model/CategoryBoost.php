<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model;

use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost as CategoryBoostResource;
use Magento\Framework\Model\AbstractModel;

/**
 * The mutable AbstractModel row Magento's Collection/Ui Component grid
 * machinery expects. Kept internal to the admin-grid ORM stack —
 * CategoryBoostRepository is the only place outside of it (its own
 * DataProvider/Collection) that ever touches this class; every other
 * caller in this module works with the immutable CategoryBoostRow DTO
 * instead, mirroring MerchandisingBoost's own convention exactly.
 */
class CategoryBoost extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CategoryBoostResource::class);
    }
}
