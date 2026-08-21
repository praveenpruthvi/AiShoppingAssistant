<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\AttributeSelection;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\AttributeIndexingSelectionRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves the whole checkbox list in one action — an unchecked checkbox
 * never appears in an HTML POST at all, so the template also submits a
 * hidden `all_codes` field listing every code the page actually offered;
 * anything in that set NOT present in the checked `selected_codes[]` is
 * explicitly set to not-indexed, so unchecking a previously-selected
 * attribute here genuinely deselects it (not merely "leaves it alone").
 * Goes through the exact same AttributeIndexingSelectionRepositoryInterface
 * the native grid's mass action does — see that interface's own docblock.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Aavirbhava_AiShoppingAssistant::attribute_selection';

    public function __construct(
        Context $context,
        private readonly AttributeIndexingSelectionRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();

        $selectedCodes = $this->normalizeCodes((array) $request->getParam('selected_codes', []));
        $allCodes = $this->normalizeCodes(explode(',', (string) $request->getParam('all_codes', '')));
        $unselectedCodes = array_values(array_diff($allCodes, $selectedCodes));

        try {
            if ($selectedCodes !== []) {
                $this->repository->setIndexed($selectedCodes, true);
            }

            if ($unselectedCodes !== []) {
                $this->repository->setIndexed($unselectedCodes, false);
            }

            $this->messageManager->addSuccessMessage(
                __(
                    'The attribute indexing selection was saved (%1 selected). '
                    . 'A full reindex (indexer:reindex or the Admin Playground) '
                    . 'is required for this to take effect in search results.',
                    count($selectedCodes)
                )
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @param array<mixed> $codes
     *
     * @return list<string>
     */
    private function normalizeCodes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = is_string($code) ? trim($code) : '';
            if ($code !== '') {
                $normalized[] = $code;
            }
        }

        return array_values(array_unique($normalized));
    }
}
