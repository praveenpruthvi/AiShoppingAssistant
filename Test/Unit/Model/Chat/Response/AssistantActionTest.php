<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantAction::class)]
final class AssistantActionTest extends TestCase
{
    public function testValidAction(): void
    {
        $action = new AssistantAction('compare', ['SKU-1', 'SKU-2']);

        self::assertSame('compare', $action->type);
        self::assertSame(['SKU-1', 'SKU-2'], $action->skus);
    }

    public function testRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantAction('', ['SKU-1']);
    }

    public function testRejectsEmptySkuEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantAction('compare', ['']);
    }
}
