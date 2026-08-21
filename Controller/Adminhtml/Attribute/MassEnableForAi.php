<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Attribute;

class MassEnableForAi extends AbstractMassSetIndexedForAi
{
    protected function isIndexed(): bool
    {
        return true;
    }
}
