<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchableAttribute::class)]
final class SearchableAttributeTest extends TestCase
{
    public function testAcceptsValidAttribute(): void
    {
        $attribute = new SearchableAttribute('material', 'Material', ['leather', 'cotton']);

        self::assertSame('material', $attribute->code());
        self::assertSame('Material', $attribute->label());
        self::assertSame(['leather', 'cotton'], $attribute->values());
    }

    public function testRejectsInvalidCode(): void
    {
        $this->expectException(CatalogException::class);

        new SearchableAttribute('Material-Code', 'Material', ['leather']);
    }

    public function testRejectsUppercaseCode(): void
    {
        $this->expectException(CatalogException::class);

        new SearchableAttribute('Material', 'Material', ['leather']);
    }

    public function testRejectsEmptyLabel(): void
    {
        $this->expectException(CatalogException::class);

        new SearchableAttribute('material', '', ['leather']);
    }

    public function testRejectsMissingValues(): void
    {
        $this->expectException(CatalogException::class);

        new SearchableAttribute('material', 'Material', []);
    }

    public function testRejectsEmptyValue(): void
    {
        $this->expectException(CatalogException::class);

        new SearchableAttribute('material', 'Material', ['leather', '']);
    }
}
