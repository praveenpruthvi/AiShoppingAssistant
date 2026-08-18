<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Tool\BlogContentSearcherInterface;

/**
 * Registered by default (see etc/di.xml) because no blog module was found
 * installed in this Magento instance at the time search_store_content was
 * built (checked composer.json/module:status for Magefan_Blog, Amasty and
 * Mageplaza blog packages — none present). Always returns an empty list;
 * search_store_content treats that as "no blog content type available"
 * rather than an error.
 */
final class NullBlogContentSearcher implements BlogContentSearcherInterface
{
    public function search(int $storeId, string $query, int $limit): array
    {
        return [];
    }
}
