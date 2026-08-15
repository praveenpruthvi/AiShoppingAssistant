<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretValue::class)]
final class SecretValueTest extends TestCase
{
    private const SECRET = 'sk-super-secret-123';

    public function testRevealReturnsStoredSecret(): void
    {
        $secret = new SecretValue(self::SECRET);

        self::assertSame(self::SECRET, $secret->reveal());
        self::assertFalse($secret->isEmpty());
    }

    public function testEmptySecretIsAllowed(): void
    {
        $secret = new SecretValue('');

        self::assertTrue($secret->isEmpty());
        self::assertSame('', $secret->reveal());
    }

    public function testEmptyFactoryReturnsEmptySecret(): void
    {
        self::assertTrue(SecretValue::empty()->isEmpty());
    }

    public function testDebugInfoRedactsSecret(): void
    {
        $secret = new SecretValue(self::SECRET);

        $debug = $secret->__debugInfo();

        self::assertStringNotContainsString(self::SECRET, print_r($debug, true));
        self::assertSame('********', $debug['value']);
    }

    public function testJsonSerializationRedactsSecret(): void
    {
        $secret = new SecretValue(self::SECRET);

        $encoded = json_encode($secret);

        self::assertIsString($encoded);
        self::assertStringNotContainsString(self::SECRET, $encoded);
        self::assertSame('"********"', $encoded);
    }

    public function testSecretValueDefinesNoStringConversion(): void
    {
        self::assertFalse(method_exists(SecretValue::class, '__toString'));
    }

    public function testCastingSecretToStringFailsClosed(): void
    {
        $secret = new SecretValue(self::SECRET);

        $this->expectException(\Error::class);

        (string) $secret;
    }

    public function testSecretCannotLeakThroughExceptionMessageConstruction(): void
    {
        $secret = new SecretValue(self::SECRET);

        $this->expectException(\Error::class);

        throw new \RuntimeException('configuration failed for ' . $secret);
    }
}
