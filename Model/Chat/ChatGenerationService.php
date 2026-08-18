<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Magento\Framework\Phrase;

/**
 * Store-scoped chat generation service.
 *
 * Mirrors EmbeddingGenerationService: activates and scopes to a store view,
 * reads store-scoped LLM configuration, resolves exactly the primary
 * provider (never a fallback), and never writes anything. Configuration,
 * provider-resolution, secret, and unexpected provider failures all surface
 * through the generic Provider* exception hierarchy (not a parallel
 * Chat*Exception hierarchy) so FallbackEligibilityPolicy can already
 * evaluate them and a later fallback-orchestration task can wrap a call to
 * chat() without reworking this service.
 */
final class ChatGenerationService implements ChatGenerationServiceInterface
{
    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ConfiguredProviderResolverInterface $providerResolver,
        private readonly SecretReaderInterface $secretReader
    ) {
    }

    /**
     * @param non-empty-list<\Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    public function chat(int $storeId, array $messages, array $tools = [], ?array $responseSchema = null): ChatResponse
    {
        $this->storeScopeProvider->requireActive($storeId);

        $config = $this->readLlmConfig($storeId);

        $provider = $this->resolveProvider($storeId);

        $apiKey = $this->readApiKey($storeId);

        $request = new ChatRequest(
            storeId: $storeId,
            messages: $messages,
            model: $config->model(),
            baseUrl: $config->baseUrl(),
            apiKey: $apiKey,
            timeoutSeconds: $config->timeoutSeconds(),
            tools: $tools,
            responseSchema: $responseSchema,
            maxOutputTokens: $config->maxOutputTokens()
        );

        try {
            return $provider->chat($request);
        } catch (ProviderException $cause) {
            throw $cause;
        } catch (\Exception $cause) {
            throw new ProviderInvalidResponseException(
                new Phrase('The chat provider returned an unexpected error.'),
                $cause
            );
        }
    }

    private function readLlmConfig(int $storeId): LlmConfigInterface
    {
        try {
            return $this->configurationReader->readLlm($storeId);
        } catch (ConfigurationException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The chat configuration is incomplete.'),
                $cause
            );
        }
    }

    private function resolveProvider(int $storeId): LlmProviderInterface
    {
        try {
            return $this->providerResolver->primaryLlmProvider($storeId);
        } catch (ProviderNotFoundException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The configured chat provider is not available.'),
                $cause
            );
        }
    }

    private function readApiKey(int $storeId): SecretValue
    {
        try {
            return $this->secretReader->getPrimaryLlmApiKey($storeId);
        } catch (ConfigurationException $cause) {
            throw new ProviderConfigurationException(
                new Phrase('The chat API key could not be read.'),
                $cause
            );
        }
    }
}
