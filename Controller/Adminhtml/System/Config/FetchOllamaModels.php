<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\System\Config;

use Aavirbhava\AiShoppingAssistant\Api\Provider\OllamaModelListServiceInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Small, single-purpose AJAX action backing the "Fetch Ollama Models"
 * button (Block\Adminhtml\System\Config\OllamaModelField) — mirrors
 * Controller\Adminhtml\Playground\TestConnection's shape exactly (Task 9):
 * one focused action, no new subsystem. Takes the base URL straight from
 * the live, possibly-unsaved admin form field (not from saved config —
 * the whole point is letting the admin try a URL before saving it) and
 * reports back exactly what OllamaModelListService actually got, success
 * or failure, honestly.
 *
 * Not final: Magento generates a plugin interceptor for every controller
 * action class, the same reason every other controller in this module
 * isn't final either.
 */
class FetchOllamaModels extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::config';

    private const DEFAULT_BASE_URL = 'http://localhost:11434';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly OllamaModelListServiceInterface $modelListService
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $baseUrl = trim((string) $this->getRequest()->getParam('base_url', ''));
        $baseUrl = $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL;

        $result = $this->modelListService->fetchModels($baseUrl);

        /** @var Json $json */
        $json = $this->resultJsonFactory->create();
        $json->setData([
            'successful' => $result->successful,
            'models' => $result->models,
            'message' => $result->message,
        ]);

        return $json;
    }
}
