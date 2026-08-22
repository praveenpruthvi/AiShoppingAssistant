<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\CategoryBoost;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The standalone "Category Boosts" grid — a real Ui Component grid
 * (view/adminhtml/ui_component/aavirbhava_categoryboost_listing.xml)
 * backed by Model\ResourceModel\CategoryBoost\Collection, so this page
 * and the category edit form's own boost field (Task 33's other entry
 * point) both ultimately read/write through the exact same table via the
 * exact same CategoryBoostRepositoryInterface — mirrors
 * Controller\Adminhtml\Boost\Index exactly.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::category_boost';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::categoryboost_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Category Boosts'), __('Category Boosts'));
        $resultPage->getConfig()->getTitle()->prepend(__('AI Shopping Assistant Category Boosts'));

        return $resultPage;
    }
}
