<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use InvalidArgumentException;

/**
 * One unified search_store_content result row — a CMS page, a blog post,
 * or a product. `id` is the page id / blog post id / SKU, whichever
 * applies, so the model (or a follow-up live-revalidation check for a
 * product row) has enough to reference or re-look-up the exact item.
 *
 * price/specialPrice/url are only ever populated for `type === 'product'`,
 * and only from an already live-revalidated RevalidatedProduct — never
 * from raw catalog/index data — the same discipline every other tool in
 * this module follows.
 */
final readonly class StoreContentMatch
{
    public const TYPE_CMS_PAGE = 'cms_page';
    public const TYPE_BLOG_POST = 'blog_post';
    public const TYPE_PRODUCT = 'product';

    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public string $snippet,
        public ?string $url = null,
        public ?float $price = null,
        public ?float $specialPrice = null
    ) {
        if (!in_array($type, [self::TYPE_CMS_PAGE, self::TYPE_BLOG_POST, self::TYPE_PRODUCT], true)) {
            throw new InvalidArgumentException('Unknown store content match type: ' . $type);
        }

        if ($id === '') {
            throw new InvalidArgumentException('A store content match requires a non-empty id.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'id' => $this->id,
                'title' => $this->title,
                'snippet' => $this->snippet,
                'url' => $this->url,
                'price' => $this->price,
                'special_price' => $this->specialPrice,
            ],
            static fn (mixed $value): bool => $value !== null
        );
    }
}
