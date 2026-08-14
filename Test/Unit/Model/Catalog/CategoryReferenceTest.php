<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReference;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryReference::class)]
final class CategoryReferenceTest extends TestCase
{
    public function testAcceptsValidReference(): void
    {
        $category = new CategoryReference(7, 'Shoes', 'Root / Shoes');

        self::assertSame(7, $category->categoryId());
        self::assertSame('Shoes', $category->name());
        self::assertSame('Root / Shoes', $category->path());
    }

    public function testRejectsNonPositiveId(): void
    {
        $this->expectException(CatalogException::class);

        new CategoryReference(0, 'Shoes', 'Root / Shoes');
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(CatalogException::class);

        new CategoryReference(7, '', 'Root / Shoes');
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(CatalogException::class);

        new CategoryReference(7, 'Shoes', '');
    }
}
