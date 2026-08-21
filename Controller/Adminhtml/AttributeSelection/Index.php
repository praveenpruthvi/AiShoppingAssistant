<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\AttributeSelection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * ENTRY POINT B — the primary real-world way to select attributes for
 * this module's index: a single checkbox list of every real, eligible
 * product attribute, saved in one action. Mirrors Boost\Index's own
 * hand-rolled-server-rendered-page convention (not a Ui Component form),
 * consistent with this module's established admin-UI pattern (see
 * Playground/Boost).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::attribute_selection';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::attributeselection_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Attribute Indexing Selection'), __('Attribute Indexing Selection'));
        $resultPage->getConfig()->getTitle()->prepend(__('AI Shopping Assistant Attribute Indexing Selection'));

        return $resultPage;
    }
}
