<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentHashService::class)]
final class ContentHashServiceTest extends TestCase
{
    private ContentHashService $service;

    protected function setUp(): void
    {
        $this->service = new ContentHashService();
    }

    public function testProducesSha256HexDigest(): void
    {
        $hash = $this->service->hash(['name' => 'Test']);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testIsDeterministic(): void
    {
        $payload = ['a' => 1, 'b' => ['c' => 'x', 'd' => ['1', '2']]];

        self::assertSame($this->service->hash($payload), $this->service->hash($payload));
    }

    public function testAssociativeKeyOrderIsIrrelevant(): void
    {
        $first = $this->service->hash(['name' => 'Test', 'sku' => 'A']);
        $second = $this->service->hash(['sku' => 'A', 'name' => 'Test']);

        self::assertSame($first, $second);
    }

    public function testNestedAssociativeKeyOrderIsIrrelevant(): void
    {
        $first = $this->service->hash(['attrs' => ['size' => '42', 'color' => 'red']]);
        $second = $this->service->hash(['attrs' => ['color' => 'red', 'size' => '42']]);

        self::assertSame($first, $second);
    }

    public function testListOrderMatters(): void
    {
        $first = $this->service->hash(['values' => ['a', 'b']]);
        $second = $this->service->hash(['values' => ['b', 'a']]);

        self::assertNotSame($first, $second);
    }

    public function testUnicodeIsPreserved(): void
    {
        $first = $this->service->hash(['name' => 'ಕನ್ನಡ 🚀']);
        $second = $this->service->hash(['name' => 'ಕನ್ನಡ 🚀']);

        self::assertSame($first, $second);
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(CatalogException::class);

        $this->service->hash(['name' => "\xB1\x31"]);
    }
}