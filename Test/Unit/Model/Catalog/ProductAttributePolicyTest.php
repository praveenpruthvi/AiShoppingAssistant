<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductAttributePolicy;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductAttributePolicy::class)]
final class ProductAttributePolicyTest extends TestCase
{
    private ProductAttributePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ProductAttributePolicy();
    }

    public function testAllowsPlainAttributeCode(): void
    {
        self::assertTrue($this->policy->isAllowed('material'));
        self::assertTrue($this->policy->isAllowed('color'));
        self::assertTrue($this->policy->isAllowed('size_uk'));
        self::assertTrue($this->policy->isAllowed('battery_life_minutes'));
    }

    public function testDeniesExplicitDenylist(): void
    {
        foreach ([
            'cost',
            'internal_note',
            'internal_notes',
            'admin_note',
            'admin_notes',
            'admin_instructions',
            'backend_note',
            'api_key',
            'password',
            'passwd',
            'secret_key',
            'client_secret',
            'auth_token',
            'access_token',
            'credential',
            'credentials',
            'private_key',
        ] as $code) {
            self::assertFalse($this->policy->isAllowed($code), $code);
        }
    }

    public function testDeniesObfuscatedSensitiveCodes(): void
    {
        foreach ([
            'secret_key_2',
            'credential_hint',
            'password_value',
            'auth_token_new',
            'private_key_main',
            'api_key_secret',
        ] as $code) {
            self::assertFalse($this->policy->isAllowed($code), $code);
        }
    }

    public function testDeniesInvalidCodes(): void
    {
        foreach (['Color', '9abc', 'has space', 'a-b', 'A', 'édition'] as $code) {
            self::assertFalse($this->policy->isAllowed($code), $code);
        }
    }

    public function testFilterReturnsSortedAllowedAttributes(): void
    {
        $attributes = [
            'material' => new SearchableAttribute('material', 'Material', ['leather']),
            'cost' => new SearchableAttribute('cost', 'Cost', ['99']),
            'color' => new SearchableAttribute('color', 'Color', ['black']),
        ];

        $filtered = $this->policy->filter($attributes);

        self::assertCount(2, $filtered);
        self::assertSame('color', $filtered[0]->code());
        self::assertSame('material', $filtered[1]->code());
    }

    public function testFilterDropsInvalidEntriesAndDeniedCodes(): void
    {
        $attributes = [
            'material' => new SearchableAttribute('material', 'Material', ['leather']),
            'cost' => new SearchableAttribute('cost', 'Cost', ['99']),
            'broken' => 'not-an-attribute',
            'size' => new SearchableAttribute('size', 'Size', ['42']),
        ];

        $filtered = $this->policy->filter($attributes);

        self::assertCount(2, $filtered);
        self::assertSame('material', $filtered[0]->code());
        self::assertSame('size', $filtered[1]->code());
    }
}
