<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostGrid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit/delete links for the standalone category boost grid — mirrors
 * Merchandising\BoostGrid\BoostActions exactly, adapted for this grid's
 * own controller routes.
 */
class CategoryBoostActions extends Column
{
    public const URL_PATH_EDIT = 'aavirbhava_aishoppingassistant/categoryboost/edit';
    public const URL_PATH_DELETE = 'aavirbhava_aishoppingassistant/categoryboost/delete';

    private ?Escaper $escaper = null;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (!isset($item['boost_id'])) {
                    continue;
                }

                $name = $this->getEscaper()->escapeHtmlAttr((string) ($item['category_name'] ?? $item['category_id']));

                $item[$this->getData('name')] = [
                    'edit' => [
                        'href' => $this->urlBuilder->getUrl(self::URL_PATH_EDIT, ['boost_id' => $item['boost_id']]),
                        'label' => __('Edit'),
                    ],
                    'delete' => [
                        'href' => $this->urlBuilder->getUrl(self::URL_PATH_DELETE, ['boost_id' => $item['boost_id']]),
                        'label' => __('Delete'),
                        'confirm' => [
                            'title' => __('Delete Boost'),
                            'message' => __('Are you sure you want to delete the boost for %1?', $name),
                        ],
                        'post' => true,
                    ],
                ];
            }
        }

        return $dataSource;
    }

    private function getEscaper(): Escaper
    {
        if ($this->escaper === null) {
            $this->escaper = ObjectManager::getInstance()->get(Escaper::class);
        }

        return $this->escaper;
    }
}
