<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Boost;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The standalone "Merchandising Boosts" grid — a real Ui Component grid
 * (view/adminhtml/ui_component/aavirbhava_boost_listing.xml) backed by
 * Model\ResourceModel\MerchandisingBoost\Collection, so this page and the
 * product-grid mass action's Edit/Save flow both ultimately read/write
 * through the exact same table via the exact same
 * MerchandisingBoostRepositoryInterface — see that interface's own
 * docblock for why.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::boost';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::boost_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Merchandising Boosts'), __('Merchandising Boosts'));
        $resultPage->getConfig()->getTitle()->prepend(__('AI Shopping Assistant Merchandising Boosts'));

        return $resultPage;
    }
}
