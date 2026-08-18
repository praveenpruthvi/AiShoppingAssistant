<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\StoreContentMatch;

/**
 * Keyword search against whatever blog module (if any) is installed in
 * this Magento instance, behind an interface for the same reason
 * LlmProviderInterface/EmbeddingProviderInterface are: no blog module is a
 * dependency of this module itself, so a real integration (Magefan,
 * Amasty, Mageplaza, ...) is a di.xml preference swap away, each behind
 * its own adapter reaching only that module's own public repository/API —
 * never a third-party module's internal tables directly.
 *
 * The default implementation (NullBlogContentSearcher) is registered when
 * no blog module is present; it always returns an empty list rather than
 * throwing, since "no blog content exists" is a completely ordinary,
 * expected outcome for search_store_content, not a failure.
 */
interface BlogContentSearcherInterface
{
    /**
     * @return list<StoreContentMatch>
     */
    public function search(int $storeId, string $query, int $limit): array;
}
