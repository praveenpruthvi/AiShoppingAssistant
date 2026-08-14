<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ConnectionResult;

interface LlmProviderInterface
{
    public function chat(ChatRequest $request): ChatResponse;

    public function testConnection(): ConnectionResult;
}
