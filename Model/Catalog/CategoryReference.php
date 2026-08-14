<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

final readonly class CategoryReference implements CategoryReferenceInterface
{
    public function __construct(
        private int $categoryId,
        private string $name,
        private string $path
    ) {
        if ($categoryId < 1) {
            throw new CatalogException(__('Category id must be a positive integer.'));
        }

        if ($name === '' || $path === '') {
            throw new CatalogException(__('Category name and path must not be empty.'));
        }
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }
}
