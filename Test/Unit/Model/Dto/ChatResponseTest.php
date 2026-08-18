<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Dto;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatResponse::class)]
final class ChatResponseTest extends TestCase
{
    private function response(): ChatResponse
    {
        return new ChatResponse('Hello.', [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5);
    }

    public function testUsedFallbackDefaultsToFalse(): void
    {
        self::assertFalse($this->response()->usedFallback);
    }

    public function testWithFallbackUsedReturnsANewInstanceWithEveryOtherFieldPreserved(): void
    {
        $original = $this->response();

        $updated = $original->withFallbackUsed(true);

        self::assertFalse($original->usedFallback);
        self::assertTrue($updated->usedFallback);
        self::assertSame($original->text, $updated->text);
        self::assertSame($original->provider, $updated->provider);
        self::assertSame($original->model, $updated->model);
        self::assertSame($original->latencyMilliseconds, $updated->latencyMilliseconds);
        self::assertNotSame($original, $updated);
    }
}
