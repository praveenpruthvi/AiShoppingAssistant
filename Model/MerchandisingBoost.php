<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model;

use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost as MerchandisingBoostResource;
use Magento\Framework\Model\AbstractModel;

/**
 * The mutable AbstractModel row Magento's Collection/Ui Component grid
 * machinery expects. Kept internal to the admin-grid/mass-action ORM
 * stack — MerchandisingBoostRepository is the only place outside of it
 * (its own DataProvider/Collection) that ever touches this class; every
 * other caller in this module works with the immutable
 * MerchandisingBoostRow DTO instead, this module's usual convention.
 */
class MerchandisingBoost extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(MerchandisingBoostResource::class);
    }
}
