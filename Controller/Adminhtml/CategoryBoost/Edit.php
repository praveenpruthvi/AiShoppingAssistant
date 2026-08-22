<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\CategoryBoost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the category boost edit form, scoped to an existing boost's
 * `boost_id` (GET) only — unlike Controller\Adminhtml\Boost\Edit, this
 * standalone grid entry point never CREATES a new boost: a category has
 * no bulk-selectable grid the way products do, so a NEW boost is always
 * created via the category's own edit form field instead (Task 33's
 * other entry point). This screen exists purely for reviewing/editing/
 * deleting boosts already saved, matching the task's own scope for the
 * standalone grid ("review grid... for review/edit/delete").
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::category_boost';

    public const REGISTRY_KEY_BOOST = 'aavirbhava_category_boost_existing';
    public const REGISTRY_KEY_ERROR = 'aavirbhava_category_boost_error';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly CategoryBoostRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $boostId = (int) $this->getRequest()->getParam('boost_id');

        if ($boostId < 1) {
            $this->registry->register(
                self::REGISTRY_KEY_ERROR,
                (string) __('No category boost was specified.')
            );
        } else {
            try {
                $this->registry->register(self::REGISTRY_KEY_BOOST, $this->repository->getById($boostId));
            } catch (LocalizedException $exception) {
                $this->registry->register(self::REGISTRY_KEY_ERROR, $exception->getMessage());
            }
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::categoryboost_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Category Boosts'), __('Category Boosts'));
        $resultPage->getConfig()->getTitle()->prepend(__('Category Boost'));

        return $resultPage;
    }
}
