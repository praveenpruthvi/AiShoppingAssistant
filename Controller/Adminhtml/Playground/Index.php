<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Playground\PlaygroundQueryRunnerInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Admin Playground — GET renders an empty form; POST runs the submitted
 * query through the real pipeline stages (PlaygroundQueryRunner) and
 * renders the same page with the results registered for
 * Block\Adminhtml\Playground\Index to read and display.
 *
 * One controller handles both verbs deliberately: this is a one-off
 * diagnostic tool, not a CRUD resource, and this module has no prior admin
 * UI precedent to match — a single server-rendered page, form-posts-to-
 * itself, is the simplest structure that fits (no ui_component form XML,
 * no separate AJAX "run" endpoint, no client-side results rendering).
 * Task Connection (a genuinely separate, small, unrelated concern) is its
 * own tiny AJAX action instead — see TestConnection.php.
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::playground';

    public const REGISTRY_KEY_RESULT = 'aavirbhava_playground_result';
    public const REGISTRY_KEY_ERROR = 'aavirbhava_playground_error';
    public const REGISTRY_KEY_QUERY = 'aavirbhava_playground_query';
    public const REGISTRY_KEY_CALL_LLM = 'aavirbhava_playground_call_llm';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly PlaygroundQueryRunnerInterface $queryRunner
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        if ($this->getRequest()->isPost()) {
            $this->runQuery();
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::playground');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Playground'), __('Playground'));
        $resultPage->getConfig()->getTitle()->prepend(__('AI Shopping Assistant Playground'));

        return $resultPage;
    }

    private function runQuery(): void
    {
        $queryText = trim((string) $this->getRequest()->getParam('query', ''));
        $callLlm = (bool) $this->getRequest()->getParam('call_llm', false);

        $this->registry->register(self::REGISTRY_KEY_QUERY, $queryText);
        $this->registry->register(self::REGISTRY_KEY_CALL_LLM, $callLlm);

        if ($queryText === '') {
            $this->registry->register(self::REGISTRY_KEY_ERROR, 'Enter a query to run.');

            return;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $result = $this->queryRunner->run($storeId, $queryText, $callLlm);
            $this->registry->register(self::REGISTRY_KEY_RESULT, $result);
        } catch (LocalizedException $exception) {
            // Every exception surfaced anywhere in this module's pipeline
            // is already deliberately sanitized/generic (a codebase-wide
            // discipline, not special-cased here) — safe to show directly
            // to an admin, who has more trust than a customer already.
            $this->registry->register(self::REGISTRY_KEY_ERROR, $exception->getMessage());
        }
    }
}
