<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeValueResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttributeValueResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as EavAttribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchableAttributeValueResolver::class)]
final class SearchableAttributeValueResolverTest extends TestCase
{
    /**
     * @param array<string, EavAttribute> $attributes keyed by code
     */
    private function resolver(array $attributes): SearchableAttributeValueResolver
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')
            ->willReturnCallback(
                static fn (string $entityType, string $code): mixed => $attributes[$code] ?? null
            );

        $policy = $this->createMock(ProductAttributePolicyInterface::class);
        $policy->method('isAllowed')->willReturn(true);

        return new SearchableAttributeValueResolver($eavConfig, $policy);
    }

    private function source(array $options): AbstractSource
    {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->with(false)->willReturn($options);

        return $source;
    }

    private function optionAttribute(
        string $code,
        string $label,
        array $options,
        array $storeLabels = []
    ): EavAttribute {
        $attribute = $this->createMock(EavAttribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabels')->willReturn($storeLabels);
        $attribute->method('getData')->with('frontend_label')->willReturn($label);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($this->source($options));
        $attribute->method('setData')->willReturnSelf();

        return $attribute;
    }

    private function scalarAttribute(string $code, string $label): EavAttribute
    {
        $attribute = $this->createMock(EavAttribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getStoreLabels')->willReturn([]);
        $attribute->method('getData')->with('frontend_label')->willReturn($label);
        $attribute->method('usesSource')->willReturn(false);

        return $attribute;
    }

    private function product(array $values): ProductInterface
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')
            ->willReturnCallback(static fn (string $code): mixed => $values[$code] ?? null);

        return $product;
    }

    private function config(array $codes, int $budget = 100): IndexingConfigInterface
    {
        $config = $this->createMock(IndexingConfigInterface::class);
        $config->method('searchableAttributeCodes')->willReturn($codes);
        $config->method('maxAttributeValuesPerProduct')->willReturn($budget);

        return $config;
    }

    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);
        $scope->method('websiteId')->willReturn(1);

        return $scope;
    }

    public function testResolvesOptionValuesToStoreViewLabels(): void
    {
        $attribute = $this->optionAttribute(
            'color',
            'Color',
            [
                ['value' => '10', 'label' => 'Blue'],
                ['value' => '11', 'label' => 'Red'],
            ]
        );

        $resolver = $this->resolver(['color' => $attribute]);

        $result = $resolver->resolve($this->scope(), $this->config(['color']), $this->product(['color' => '10']));

        self::assertCount(1, $result);
        self::assertInstanceOf(SearchableAttributeInterface::class, $result[0]);
        self::assertSame('color', $result[0]->code());
        self::assertSame('Color', $result[0]->label());
        self::assertSame(['Blue'], $result[0]->values());
    }

    public function testUsesStoreViewAttributeLabel(): void
    {
        $attribute = $this->optionAttribute(
            'color',
            'Color',
            [['value' => '10', 'label' => 'Blue']],
            [1 => 'Couleur']
        );

        $resolver = $this->resolver(['color' => $attribute]);

        $result = $resolver->resolve($this->scope(), $this->config(['color']), $this->product(['color' => '10']));

        self::assertSame('Couleur', $result[0]->label());
    }

    public function testResolvesMultiselectValues(): void
    {
        $attribute = $this->optionAttribute(
            'material',
            'Material',
            [
                ['value' => '20', 'label' => 'Cotton'],
                ['value' => '21', 'label' => 'Polyester'],
            ]
        );

        $resolver = $this->resolver(['material' => $attribute]);

        $result = $resolver->resolve(
            $this->scope(),
            $this->config(['material']),
            $this->product(['material' => '20,21'])
        );

        self::assertSame(['Cotton', 'Polyester'], $result[0]->values());
    }

    public function testResolvesScalarAttributeValues(): void
    {
        $attribute = $this->scalarAttribute('brand', 'Brand');

        $resolver = $this->resolver(['brand' => $attribute]);

        $result = $resolver->resolve($this->scope(), $this->config(['brand']), $this->product(['brand' => 'Acme']));

        self::assertSame('brand', $result[0]->code());
        self::assertSame(['Acme'], $result[0]->values());
    }

    public function testSkipsAttributesWithoutValues(): void
    {
        $attribute = $this->scalarAttribute('brand', 'Brand');

        $resolver = $this->resolver(['brand' => $attribute]);

        $result = $resolver->resolve($this->scope(), $this->config(['brand']), $this->product(['brand' => '']));

        self::assertSame([], $result);
    }

    public function testSkipsMissingAttributes(): void
    {
        $resolver = $this->resolver([]);

        $result = $resolver->resolve($this->scope(), $this->config(['nonexistent']), $this->product([]));

        self::assertSame([], $result);
    }

    public function testSkipsPolicyDeniedAttributes(): void
    {
        $attribute = $this->scalarAttribute('cost', 'Cost');

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $policy = $this->createMock(ProductAttributePolicyInterface::class);
        $policy->method('isAllowed')->with('cost')->willReturn(false);

        $resolver = new SearchableAttributeValueResolver($eavConfig, $policy);

        $result = $resolver->resolve($this->scope(), $this->config(['cost']), $this->product(['cost' => '42']));

        self::assertSame([], $result);
    }

    public function testEmptyAttributeCodeListReturnsNothing(): void
    {
        $resolver = $this->resolver([]);

        $result = $resolver->resolve($this->scope(), $this->config([]), $this->product(['brand' => 'Acme']));

        self::assertSame([], $result);
    }

    public function testBudgetIsSharedAcrossAttributes(): void
    {
        $color = $this->optionAttribute('color', 'Color', [['value' => '10', 'label' => 'Blue']]);
        $brand = $this->scalarAttribute('brand', 'Brand');

        $resolver = $this->resolver(['color' => $color, 'brand' => $brand]);

        $result = $resolver->resolve(
            $this->scope(),
            $this->config(['color', 'brand'], 1),
            $this->product(['color' => '10', 'brand' => 'Acme'])
        );

        self::assertCount(1, $result);
        self::assertSame('color', $result[0]->code());
    }

    public function testResultIsSortedByAttributeCode(): void
    {
        $color = $this->optionAttribute('color', 'Color', [['value' => '10', 'label' => 'Blue']]);
        $brand = $this->scalarAttribute('brand', 'Brand');

        $resolver = $this->resolver(['color' => $color, 'brand' => $brand]);

        $result = $resolver->resolve(
            $this->scope(),
            $this->config(['color', 'brand']),
            $this->product(['color' => '10', 'brand' => 'Acme'])
        );

        self::assertCount(2, $result);
        self::assertSame('brand', $result[0]->code());
        self::assertSame('color', $result[1]->code());
    }

    public function testResolverImplementsInterface(): void
    {
        $resolver = $this->resolver([]);
        self::assertInstanceOf(SearchableAttributeValueResolverInterface::class, $resolver);
    }
}
