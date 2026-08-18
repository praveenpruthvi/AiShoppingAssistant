<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ConnectionResult;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

interface LlmProviderInterface
{
    public function identifier(): string;

    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Performs a minimal live call against the provider using an explicit,
     * store-scoped config snapshot (mirrors the config carried by
     * ChatRequest), so the check exercises the same endpoint/auth path as a
     * real chat() call without the adapter retaining any state.
     */
    public function testConnection(
        int $storeId,
        string $model,
        string $baseUrl,
        SecretValue $apiKey,
        int $timeoutSeconds
    ): ConnectionResult;

    public function capabilities(): ProviderCapabilities;
}
