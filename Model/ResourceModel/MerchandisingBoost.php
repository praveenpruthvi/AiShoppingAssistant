<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Standard Magento AbstractDb resource model — used only by the admin
 * grid/mass-action's ORM stack (Model\MerchandisingBoost, its Collection,
 * and MerchandisingBoostRepository). The ranking pipeline's own read path
 * (ActiveBoostReader) intentionally bypasses this in favor of one lean,
 * scoped raw query, mirroring this module's established "no ORM in the
 * runtime hot path" convention (see DbConversationHistoryStore); both
 * still point at the exact same table, defined once here as TABLE.
 */
class MerchandisingBoost extends AbstractDb
{
    public const TABLE = 'aavirbhava_ai_merchandising_boost';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'boost_id');
    }
}
