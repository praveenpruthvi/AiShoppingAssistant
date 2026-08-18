<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\OutputValidationResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputValidationResult::class)]
final class OutputValidationResultTest extends TestCase
{
    public function testValidResultExposesTheResponse(): void
    {
        $response = new AssistantResponse('ok', [], [], [], new ResponseMetadata('openai', 'gpt-4o-mini', false));

        $result = OutputValidationResult::valid($response);

        self::assertTrue($result->isValid());
        self::assertSame($response, $result->response());
        self::assertNull($result->reasonCode());
    }

    public function testInvalidResultExposesTheReasonCode(): void
    {
        $result = OutputValidationResult::invalid('malformed_response');

        self::assertFalse($result->isValid());
        self::assertSame('malformed_response', $result->reasonCode());
    }

    public function testResponseThrowsWhenResultWasInvalid(): void
    {
        $result = OutputValidationResult::invalid('malformed_response');

        $this->expectException(LogicException::class);
        $result->response();
    }
}
