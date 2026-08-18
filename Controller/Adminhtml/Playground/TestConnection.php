<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Small, single-purpose AJAX action wiring up LlmProviderInterface::
 * testConnection() (built in Task 1, never called from anywhere in admin
 * until now) to a button — not a new subsystem, just the missing wire.
 * Reuses ConfiguredProviderResolverInterface/ConfigurationReaderInterface/
 * SecretReaderInterface exactly as FallbackChatGenerationService and every
 * other real caller already does, so this exercises the identical
 * provider/config/secret resolution path a real chat call would.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::playground';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly SecretReaderInterface $secretReader,
        private readonly ConfiguredProviderResolverInterface $providerResolver
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $llmConfig = $this->configurationReader->readLlm($storeId);
            $provider = $this->providerResolver->primaryLlmProvider($storeId);

            $connection = $provider->testConnection(
                $storeId,
                $llmConfig->model(),
                $llmConfig->baseUrl(),
                $this->secretReader->getPrimaryLlmApiKey($storeId),
                $llmConfig->timeoutSeconds()
            );

            $result->setData([
                'successful' => $connection->successful,
                'message' => $connection->message,
                'error_code' => $connection->sanitizedErrorCode,
            ]);
        } catch (LocalizedException $exception) {
            $result->setData([
                'successful' => false,
                'message' => $exception->getMessage(),
                'error_code' => null,
            ]);
        }

        return $result;
    }
}
