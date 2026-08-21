<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\PromotionContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\ProductPromotion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromotionContextFormatter::class)]
final class PromotionContextFormatterTest extends TestCase
{
    public function testReturnsNullForNoPromotions(): void
    {
        self::assertNull((new PromotionContextFormatter())->format([]));
    }

    public function testFormatsEachPromotionsRealFacts(): void
    {
        $message = (new PromotionContextFormatter())->format([
            'SKU-1' => new ProductPromotion('SKU-1', 50.00, 40.00),
        ]);

        self::assertNotNull($message);
        self::assertSame('system', $message->role);
        self::assertStringContainsString('SKU-1', $message->content);
        self::assertStringContainsString('50.00', $message->content);
        self::assertStringContainsString('40.00', $message->content);
        self::assertStringContainsString('20% off', $message->content);
    }

    public function testInstructsTheModelNeverToInventBeyondWhatIsListed(): void
    {
        $message = (new PromotionContextFormatter())->format([
            'SKU-1' => new ProductPromotion('SKU-1', 50.00, 40.00),
        ]);

        self::assertStringContainsString('Never state a discount', $message->content);
    }
}
