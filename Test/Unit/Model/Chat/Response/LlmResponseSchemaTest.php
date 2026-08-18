<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmResponseSchema::class)]
final class LlmResponseSchemaTest extends TestCase
{
    public function testTopLevelPropertiesMatchTheContract(): void
    {
        $schema = LlmResponseSchema::schema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('message', $schema['properties']);
        self::assertArrayHasKey('product_skus', $schema['properties']);
        self::assertArrayHasKey('follow_up_questions', $schema['properties']);
        self::assertArrayHasKey('actions', $schema['properties']);
    }

    public function testNeverExposesAPriceUrlOrStockField(): void
    {
        $encoded = json_encode(LlmResponseSchema::schema());

        self::assertStringNotContainsString('price', $encoded);
        self::assertStringNotContainsString('url', $encoded);
        self::assertStringNotContainsString('stock', $encoded);
    }

    public function testEveryObjectLevelIsStrictModeCompatible(): void
    {
        // OpenAI structured-output strict mode requires every object's
        // "required" array to list every one of its own "properties" keys,
        // and additionalProperties: false at every level.
        $this->assertStrictObject(LlmResponseSchema::schema());
    }

    private function assertStrictObject(array $node): void
    {
        if (($node['type'] ?? null) === 'object') {
            self::assertFalse($node['additionalProperties'] ?? null);
            self::assertSame(
                array_keys($node['properties']),
                $node['required'] ?? null
            );

            foreach ($node['properties'] as $property) {
                $this->assertStrictObject($property);
            }
        }

        if (($node['type'] ?? null) === 'array' && isset($node['items'])) {
            $this->assertStrictObject($node['items']);
        }
    }
}
