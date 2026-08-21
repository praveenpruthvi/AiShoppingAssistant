<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\ProviderCost;

use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves one provider's pricing at a time. The submitted identifier must
 * belong to the real, currently-registered LLM provider list (checked via
 * LlmProviderRegistryInterface::has() — the same registry the admin
 * dropdown itself is built from) before ever reaching the repository, so a
 * tampered request can't write an arbitrary row.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::provider_cost';

    public function __construct(
        Context $context,
        private readonly ProviderCostRepositoryInterface $repository,
        private readonly LlmProviderRegistryInterface $llmProviderRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();

        $identifier = trim((string) $request->getParam('provider', ''));

        if (!$this->llmProviderRegistry->has($identifier)) {
            $this->messageManager->addErrorMessage(__('Select a valid LLM provider.'));

            return $resultRedirect->setPath('*/*/index');
        }

        $inputPrice = (float) $request->getParam('price_per_1k_input_tokens', 0);
        $outputPrice = (float) $request->getParam('price_per_1k_output_tokens', 0);

        try {
            $this->repository->setPrice($identifier, $inputPrice, $outputPrice);
            $this->messageManager->addSuccessMessage(__('Pricing for "%1" was saved.', $identifier));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $resultRedirect->setPath('*/*/index', ['provider' => $identifier]);
    }
}
