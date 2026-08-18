<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantResponse::class)]
final class AssistantResponseTest extends TestCase
{
    private function metadata(): ResponseMetadata
    {
        return new ResponseMetadata('openai', 'gpt-4o-mini', false);
    }

    public function testValidResponseWithNoProducts(): void
    {
        $response = new AssistantResponse('No matches found.', [], [], [], $this->metadata());

        self::assertSame('No matches found.', $response->message);
        self::assertSame([], $response->products);
    }

    public function testValidResponseWithProductsAndActions(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');
        $result = new ProductResult($product, 'Good fit.');
        $action = new AssistantAction('compare', ['SKU-1']);

        $response = new AssistantResponse('Here you go.', [$result], ['Size?'], [$action], $this->metadata());

        self::assertSame([$result], $response->products);
        self::assertSame(['Size?'], $response->followUpQuestions);
        self::assertSame([$action], $response->actions);
    }

    public function testRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantResponse('', [], [], [], $this->metadata());
    }

    public function testRejectsANonProductResultEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantResponse('ok', ['not-a-product-result'], [], [], $this->metadata());
    }

    public function testRejectsAnEmptyFollowUpQuestion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantResponse('ok', [], [''], [], $this->metadata());
    }

    public function testRejectsANonActionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssistantResponse('ok', [], [], ['not-an-action'], $this->metadata());
    }
}
