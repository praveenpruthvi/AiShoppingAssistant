<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;

/**
 * Keyword search against Magento's own CMS pages — title/content LIKE
 * match, active pages only, scoped to the requesting store view (plus
 * "all store views" pages, via the collection's own addStoreFilter()).
 *
 * Uses Magento_Cms's standard collection factory rather than
 * PageRepositoryInterface::getList() — the collection's addStoreFilter()
 * is the well-established, precedented way to scope CMS pages to a store
 * view (the same mechanism the admin CMS grid itself uses); reproducing
 * that scoping through SearchCriteria filter groups against the
 * repository would be more code for an identical result. CMS pages are
 * typically few (tens, not thousands), so a LIKE-based scan is
 * appropriately simple for this volume — no separate index is built for
 * this content type.
 */
final class CmsPageContentSearcher
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ContentSearchTextUtility $textUtility
    ) {
    }

    /**
     * @return list<StoreContentMatch>
     */
    public function search(int $storeId, string $query, int $limit): array
    {
        $escaped = $this->textUtility->escapeLike($query);

        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter(
            ['title', 'content'],
            [['like' => "%{$escaped}%"], ['like' => "%{$escaped}%"]]
        );
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $matches = [];
        foreach ($collection as $page) {
            $matches[] = new StoreContentMatch(
                StoreContentMatch::TYPE_CMS_PAGE,
                (string) $page->getId(),
                (string) $page->getTitle(),
                $this->textUtility->snippet((string) $page->getContent(), $query)
            );
        }

        return $matches;
    }
}
