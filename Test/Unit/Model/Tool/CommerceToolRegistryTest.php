<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CommerceToolRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolNotFoundException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommerceToolRegistry::class)]
final class CommerceToolRegistryTest extends TestCase
{
    private function tool(string $name): CommerceToolInterface
    {
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn($name);

        return $tool;
    }

    public function testEmptyRegistryHasNothing(): void
    {
        $registry = new CommerceToolRegistry();

        self::assertFalse($registry->has('search_products'));
        self::assertSame([], $registry->all());
    }

    public function testHasAndGetResolveARegisteredTool(): void
    {
        $tool = $this->tool('search_products');
        $registry = new CommerceToolRegistry(['search_products' => $tool]);

        self::assertTrue($registry->has('search_products'));
        self::assertSame($tool, $registry->get('search_products'));
        self::assertSame(['search_products' => $tool], $registry->all());
    }

    public function testGetThrowsForAnUnregisteredName(): void
    {
        $registry = new CommerceToolRegistry();

        $this->expectException(ToolNotFoundException::class);
        $registry->get('search_products');
    }

    public function testRejectsAKeyThatIsNotValidSnakeCase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommerceToolRegistry(['Search-Products' => $this->tool('Search-Products')]);
    }

    public function testRejectsAToolWhoseNameDoesNotMatchItsKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommerceToolRegistry(['search_products' => $this->tool('get_product_details')]);
    }

    public function testRejectsAnEntryThatDoesNotImplementTheInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommerceToolRegistry(['search_products' => new \stdClass()]);
    }
}
