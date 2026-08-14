<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ConnectionResult;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

/**
 * Minimal fake used by provider contract tests. It intentionally supports the
 * full feature set so contract tests can probe every capability.
 */
final class FakeLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly ProviderCapabilities $capabilities = new ProviderCapabilities(
            chatGeneration: true,
            toolCalling: true,
            structuredOutput: true,
            streaming: true
        )
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        throw new \RuntimeException('Fake LLM provider is not expected to be invoked.');
    }

    public function testConnection(): ConnectionResult
    {
        throw new \RuntimeException('Fake LLM provider is not expected to be invoked.');
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }
}