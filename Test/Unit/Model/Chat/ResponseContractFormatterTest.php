<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ResponseContractFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseContractFormatter::class)]
final class ResponseContractFormatterTest extends TestCase
{
    public function testFormatsAsASystemMessageNamingEveryRequiredField(): void
    {
        $formatter = new ResponseContractFormatter();

        $message = $formatter->format();

        self::assertSame('system', $message->role);
        self::assertStringContainsString('message', $message->content);
        self::assertStringContainsString('product_skus', $message->content);
        self::assertStringContainsString('follow_up_questions', $message->content);
        self::assertStringContainsString('actions', $message->content);
    }

    public function testInstructsPopulatingProductSkusForDescriptiveAnswersTooNotOnlyRecommendations(): void
    {
        $formatter = new ResponseContractFormatter();

        $message = $formatter->format();

        self::assertStringContainsString('not only for recommendations', $message->content);
        self::assertStringContainsString('informational answer', $message->content);
    }
}
