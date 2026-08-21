<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Attribute;

class MassDisableForAi extends AbstractMassSetIndexedForAi
{
    protected function isIndexed(): bool
    {
        return false;
    }
}
