<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductDocumentSchema::class)]
final class ProductDocumentSchemaTest extends TestCase
{
    public function testCurrentSchemaVersionIsOne(): void
    {
        self::assertSame(1, ProductDocumentSchema::VERSION);
    }
}
