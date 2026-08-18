<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseMetadata::class)]
final class ResponseMetadataTest extends TestCase
{
    public function testValidMetadata(): void
    {
        $metadata = new ResponseMetadata('openai', 'gpt-4o-mini', false);

        self::assertSame('openai', $metadata->provider);
        self::assertSame('gpt-4o-mini', $metadata->model);
        self::assertFalse($metadata->fallbackUsed);
    }

    public function testRejectsEmptyProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResponseMetadata('', 'gpt-4o-mini', false);
    }

    public function testRejectsEmptyModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResponseMetadata('openai', '', false);
    }
}
