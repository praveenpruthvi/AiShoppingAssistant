<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Boost;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the boost form, scoped either to:
 * - `selected[]` (POST) — the product grid's own "Add to AI Assistant
 *   Boost" mass action lands here via a real, standard Magento hidden-form
 *   POST (Magento_Ui/js/grid/massactions.js's own default callback,
 *   mirroring core's real "Update attributes" mass action, which uses this
 *   exact same full-page-form pattern rather than a JS modal — see the
 *   Task 32 status report for why a JS modal was deliberately not built);
 * - `product_id` (GET) — a single new boost for one product, from the
 *   standalone grid's own "Add New" link;
 * - `boost_id` (GET) — editing an existing boost row, from the standalone
 *   grid's own edit link.
 *
 * "Select all across every page" (Magento_Ui's `excluded`/`namespace`
 * mass-action mode) is explicitly out of this task's scope — handled by
 * showing a clear error rather than silently boosting the wrong set or
 * crashing.
 */
class Edit extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::boost';

    public const REGISTRY_KEY_PRODUCT_IDS = 'aavirbhava_boost_product_ids';
    public const REGISTRY_KEY_BOOST = 'aavirbhava_boost_existing';
    public const REGISTRY_KEY_ERROR = 'aavirbhava_boost_error';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly MerchandisingBoostRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $request = $this->getRequest();

        if ($request->getParam('excluded') !== null && $request->getParam('excluded') !== false) {
            $this->registry->register(
                self::REGISTRY_KEY_ERROR,
                (string) __(
                    'Selecting "all products across every page" is not supported for this action — '
                    . 'please select individual products explicitly instead.'
                )
            );
        } elseif ($request->getParam('boost_id') !== null) {
            $this->loadExistingBoost((int) $request->getParam('boost_id'));
        } else {
            $this->loadProductIds($request->getParam('selected'), $request->getParam('product_id'));
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Aavirbhava_AiShoppingAssistant::boost_index');
        $resultPage->addBreadcrumb(__('AI Shopping Assistant'), __('AI Shopping Assistant'));
        $resultPage->addBreadcrumb(__('Merchandising Boosts'), __('Merchandising Boosts'));
        $resultPage->getConfig()->getTitle()->prepend(__('Merchandising Boost'));

        return $resultPage;
    }

    private function loadExistingBoost(int $boostId): void
    {
        try {
            $boost = $this->repository->getById($boostId);
            $this->registry->register(self::REGISTRY_KEY_BOOST, $boost);
            $this->registry->register(self::REGISTRY_KEY_PRODUCT_IDS, [$boost->productId()]);
        } catch (LocalizedException $exception) {
            $this->registry->register(self::REGISTRY_KEY_ERROR, $exception->getMessage());
        }
    }

    /**
     * @param mixed $selected the product grid mass action's own `selected[]` POST param
     * @param mixed $productId a single product id from a GET link
     */
    private function loadProductIds(mixed $selected, mixed $productId): void
    {
        $ids = [];

        if (is_array($selected)) {
            foreach ($selected as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        } elseif ($productId !== null && is_numeric($productId) && (int) $productId > 0) {
            $ids[] = (int) $productId;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            $this->registry->register(
                self::REGISTRY_KEY_ERROR,
                (string) __('No products were selected — go back to the product grid and select at least one.')
            );

            return;
        }

        $this->registry->register(self::REGISTRY_KEY_PRODUCT_IDS, $ids);
    }
}
