<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\ProviderCost;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The dynamic, per-provider cost admin screen: an add/edit form plus a
 * review grid of every currently-configured provider's pricing. Mirrors
 * AttributeSelection\Index's own hand-rolled-server-rendered-page
 * convention (not a Ui Component form), consistent with this module's
 * established admin-UI pattern (see Playground/Boost/AttributeSelection).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::provider_cost';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::providercost_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Provider Cost Pricing'), __('Provider Cost Pricing'));
        $resultPage->getConfig()->getTitle()->prepend(__('AI Shopping Assistant Provider Cost Pricing'));

        return $resultPage;
    }
}
