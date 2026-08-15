<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\IndexingConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexingConfig::class)]
final class IndexingConfigTest extends TestCase
{
    public function testExposesAllValues(): void
    {
        $config = new IndexingConfig(100, ['color', 'size'], true, false, false, 50, 'aavirbhava_ai');

        self::assertInstanceOf(IndexingConfigInterface::class, $config);
        self::assertSame(100, $config->batchSize());
        self::assertSame(['color', 'size'], $config->searchableAttributeCodes());
        self::assertTrue($config->includeShortDescription());
        self::assertFalse($config->includeLongDescription());
        self::assertFalse($config->aggregateConfigurableVariants());
        self::assertSame(50, $config->maxAttributeValuesPerProduct());
        self::assertSame('aavirbhava_ai', $config->indexPrefix());
    }

    public function testRejectsNonPositiveBatchSize(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexingConfig(0, [], true, true, false, 100, 'aavirbhava_ai');
    }

    public function testRejectsInvalidAttributeCode(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexingConfig(100, ['Bad Code'], true, true, false, 100, 'aavirbhava_ai');
    }

    public function testRejectsNonPositiveAttributeValueBudget(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexingConfig(100, [], true, true, false, 0, 'aavirbhava_ai');
    }

    public function testRejectsInvalidIndexPrefix(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexingConfig(100, [], true, true, false, 100, 'Invalid-Prefix!');
    }
}
