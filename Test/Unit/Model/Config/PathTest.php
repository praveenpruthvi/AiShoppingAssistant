<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    public function testEveryConfigurationPathUsesModulePrefix(): void
    {
        $constants = (new ReflectionClass(Path::class))->getConstants();

        foreach ($constants as $name => $value) {
            if ($name === 'PREFIX') {
                continue;
            }

            self::assertIsString($value);
            self::assertStringStartsWith(Path::PREFIX, $value, $name);
        }
    }

    public function testConfigurationPathsAreUnique(): void
    {
        $constants = (new ReflectionClass(Path::class))->getConstants();
        unset($constants['PREFIX']);

        self::assertSameSize($constants, array_unique($constants));
    }
}
