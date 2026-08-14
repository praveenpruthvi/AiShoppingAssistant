<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Magento\Framework\Phrase;

/**
 * Store-scoped embedding generation service.
 *
 * Activates and scopes to a store view, reads store-scoped embedding
 * configuration, resolves exactly one provider (never a fallback), validates
 * inputs and the returned result, and never writes anything. Configuration,
 * provider-resolution, secret, and unexpected provider failures all surface as
 * sanitized embedding exceptions.
 */
final class EmbeddingGenerationService implements EmbeddingGenerationServiceInterface
{
    public const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ConfiguredProviderResolverInterface $providerResolver,
        private readonly SecretReaderInterface $secretReader,
        private readonly EmbeddingInputValidator $inputValidator,
        private readonly EmbeddingResultValidator $resultValidator
    ) {
    }

    /**
     * @param list<string> $texts
     */
    public function embed(int $storeId, EmbeddingInputTypeInterface $inputType, array $texts): EmbeddingResultInterface
    {
        $this->storeScopeProvider->requireActive($storeId);

        $config = $this->readEmbeddingConfig($storeId);

        $inputs = $this->inputValidator->validate($texts);

        $provider = $this->resolveProvider($storeId);

        $apiKey = $this->readApiKey($storeId);

        $request = new EmbeddingRequest(
            $storeId,
            $inputType,
            $inputs,
            $config->model(),
            $config->baseUrl(),
            $apiKey,
            self::TIMEOUT_SECONDS,
            $config->dimensions()
        );

        try {
            $result = $provider->embed($request);
        } catch (ProviderException $cause) {
            throw $cause;
        } catch (\Exception $cause) {
            throw new EmbeddingResponseException(
                new Phrase('The embedding provider returned an unexpected error.'),
                $cause
            );
        }

        $this->resultValidator->validate(
            $result,
            array_map(
                static fn ($input): string => $input->identifier(),
                $inputs
            ),
            $config->dimensions()
        );

        return $result;
    }

    private function readEmbeddingConfig(int $storeId): EmbeddingConfigInterface
    {
        try {
            return $this->configurationReader->readEmbedding($storeId);
        } catch (ConfigurationException $cause) {
            throw new EmbeddingConfigurationException(
                new Phrase('The embedding configuration is incomplete.'),
                $cause
            );
        }
    }

    private function resolveProvider(int $storeId): EmbeddingProviderInterface
    {
        try {
            return $this->providerResolver->embeddingProvider($storeId);
        } catch (ProviderNotFoundException $cause) {
            throw new EmbeddingConfigurationException(
                new Phrase('The configured embedding provider is not available.'),
                $cause
            );
        }
    }

    private function readApiKey(int $storeId): SecretValue
    {
        try {
            return $this->secretReader->getEmbeddingApiKey($storeId);
        } catch (ConfigurationException $cause) {
            throw new EmbeddingConfigurationException(
                new Phrase('The embedding API key could not be read.'),
                $cause
            );
        }
    }
}