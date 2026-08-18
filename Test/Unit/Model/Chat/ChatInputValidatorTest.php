<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatInputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Exception\ChatInputException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatInputValidator::class)]
final class ChatInputValidatorTest extends TestCase
{
    public function testTrimsAndReturnsAValidMessage(): void
    {
        $validator = new ChatInputValidator();

        $result = $validator->validate('  Show me waterproof phones.  ', $this->guardrails(1000));

        self::assertSame('Show me waterproof phones.', $result);
    }

    public function testEmptyMessageIsRejected(): void
    {
        $validator = new ChatInputValidator();

        $this->expectException(ChatInputException::class);
        $validator->validate('   ', $this->guardrails(1000));
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $validator = new ChatInputValidator();

        $this->expectException(ChatInputException::class);
        $validator->validate("invalid \xB1\x31 sequence", $this->guardrails(1000));
    }

    public function testMessageExceedingConfiguredLengthIsRejected(): void
    {
        $validator = new ChatInputValidator();

        $this->expectException(ChatInputException::class);
        $validator->validate(str_repeat('a', 11), $this->guardrails(10));
    }

    public function testMessageAtExactlyTheLimitIsAccepted(): void
    {
        $validator = new ChatInputValidator();

        $result = $validator->validate(str_repeat('a', 10), $this->guardrails(10));

        self::assertSame(10, mb_strlen($result));
    }

    public function testMultibyteCharactersAreCountedAsCharactersNotBytes(): void
    {
        $validator = new ChatInputValidator();

        // 7 multibyte characters, well under a 10-character limit even though
        // they occupy more than 10 bytes.
        $result = $validator->validate('日本語のテスト', $this->guardrails(10));

        self::assertSame('日本語のテスト', $result);
    }

    private function guardrails(int $maxInputCharacters): GuardrailConfigInterface
    {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('maxInputCharacters')->willReturn($maxInputCharacters);

        return $guardrails;
    }
}
