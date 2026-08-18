<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Playground;

use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundResult;

interface PlaygroundQueryRunnerInterface
{
    public function run(int $storeId, string $queryText, bool $callLlm): PlaygroundResult;
}
