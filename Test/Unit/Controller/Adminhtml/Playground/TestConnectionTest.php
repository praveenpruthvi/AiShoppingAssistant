<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Controller\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\TestConnection;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ConnectionResult;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves TestConnection resolves the exact same provider/config/secret path
 * a real chat call would (ConfiguredProviderResolverInterface +
 * ConfigurationReaderInterface + SecretReaderInterface), calls
 * LlmProviderInterface::testConnection() with it, and always returns a
 * clean JSON payload — success or failure — never letting an exception
 * propagate to the admin AJAX caller.
 */
#[CoversClass(TestConnection::class)]
final class TestConnectionTest extends TestCase
{
    private const STORE_ID = 7;

    public function testSuccessfulConnectionReturnsTheProvidersResultAsJson(): void
    {
        $connection = ConnectionResult::success('Connected.');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects(self::once())
            ->method('testConnection')
            ->with(
                self::STORE_ID,
                'gpt-4o',
                'https://api.openai.com',
                self::callback(static fn (SecretValue $secret): bool => $secret->reveal() === 'sk-secret'),
                20
            )
            ->willReturn($connection);

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'successful' => true,
            'message' => 'Connected.',
            'error_code' => null,
        ]);

        $controller = $this->controller(provider: $provider, jsonResult: $jsonResult);

        $controller->execute();
    }

    public function testFailedConnectionStillReturnsACleanJsonPayload(): void
    {
        $connection = ConnectionResult::failure('Invalid API key.', 'auth_failed');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('testConnection')->willReturn($connection);

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'successful' => false,
            'message' => 'Invalid API key.',
            'error_code' => 'auth_failed',
        ]);

        $controller = $this->controller(provider: $provider, jsonResult: $jsonResult);

        $controller->execute();
    }

    public function testAnExceptionAnywhereInResolutionIsCaughtAndReportedAsAFailureRatherThanPropagating(): void
    {
        $providerResolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $providerResolver->method('primaryLlmProvider')->willThrowException(
            new LocalizedException(new Phrase('No LLM provider is configured.'))
        );

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'successful' => false,
            'message' => 'No LLM provider is configured.',
            'error_code' => null,
        ]);

        $controller = $this->controller(providerResolver: $providerResolver, jsonResult: $jsonResult);

        $controller->execute();
    }

    private function controller(
        ?LlmProviderInterface $provider = null,
        ?ConfiguredProviderResolverInterface $providerResolver = null,
        ?Json $jsonResult = null
    ): TestConnection {
        $context = $this->createMock(Context::class);

        $jsonResult ??= $this->createMock(Json::class);
        $jsonResultFactory = $this->createMock(JsonFactory::class);
        $jsonResultFactory->method('create')->willReturn($jsonResult);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('model')->willReturn('gpt-4o');
        $llmConfig->method('baseUrl')->willReturn('https://api.openai.com');
        $llmConfig->method('timeoutSeconds')->willReturn(20);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);

        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getPrimaryLlmApiKey')->with(self::STORE_ID)->willReturn(new SecretValue('sk-secret'));

        if ($providerResolver === null) {
            $providerResolver = $this->createMock(ConfiguredProviderResolverInterface::class);
            $providerResolver->method('primaryLlmProvider')
                ->with(self::STORE_ID)
                ->willReturn($provider ?? $this->createMock(LlmProviderInterface::class));
        }

        return new TestConnection(
            $context,
            $jsonResultFactory,
            $storeManager,
            $configurationReader,
            $secretReader,
            $providerResolver
        );
    }
}
