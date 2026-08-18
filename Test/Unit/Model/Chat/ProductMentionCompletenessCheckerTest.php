<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductMentionCompletenessChecker;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductMentionCompletenessChecker::class)]
final class ProductMentionCompletenessCheckerTest extends TestCase
{
    private function checker(): ProductMentionCompletenessChecker
    {
        return new ProductMentionCompletenessChecker();
    }

    private function product(string $sku, string $name): RevalidatedProduct
    {
        return new RevalidatedProduct(1, $sku, $name, 10.0, null, 'https://store.test/' . $sku, '2026-08-16T00:00:00+00:00');
    }

    public function testFlagsANamedProductThatWasNotSelected(): void
    {
        $products = [$this->product('SKU-1', 'Jade Yoga Jacket'), $this->product('SKU-2', 'Montana Wind Jacket')];

        $missing = $this->checker()->findMissingProducts(
            'Here are two jackets: the Jade Yoga Jacket and the Montana Wind Jacket.',
            ['SKU-1'],
            $products
        );

        self::assertCount(1, $missing);
        self::assertSame('SKU-2', $missing[0]->sku);
    }

    public function testReturnsNothingWhenEveryNamedProductWasSelected(): void
    {
        $products = [$this->product('SKU-1', 'Jade Yoga Jacket')];

        $missing = $this->checker()->findMissingProducts(
            'The Jade Yoga Jacket is a great fit.',
            ['SKU-1'],
            $products
        );

        self::assertSame([], $missing);
    }

    public function testDoesNotFlagAProductWhoseNameNeverAppearsInTheMessage(): void
    {
        $products = [$this->product('SKU-1', 'Jade Yoga Jacket')];

        $missing = $this->checker()->findMissingProducts(
            'Here are some jackets you might like.',
            [],
            $products
        );

        self::assertSame([], $missing);
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $products = [$this->product('SKU-1', 'Jade Yoga Jacket')];

        $missing = $this->checker()->findMissingProducts(
            'the JADE YOGA JACKET is on sale',
            [],
            $products
        );

        self::assertCount(1, $missing);
    }

    public function testDoesNotFlagAParaphrasedMentionNotMatchingTheExactName(): void
    {
        // Documented limitation, not a bug: a mechanical substring check
        // can't catch a paraphrase ("the Jade jacket" instead of the exact
        // "Jade Yoga Jacket"), so under-reporting here is expected.
        $products = [$this->product('SKU-1', 'Jade Yoga Jacket')];

        $missing = $this->checker()->findMissingProducts(
            'the Jade jacket looks great for yoga',
            [],
            $products
        );

        self::assertSame([], $missing);
    }

    public function testEmptyCandidateListReturnsNothing(): void
    {
        self::assertSame([], $this->checker()->findMissingProducts('anything', [], []));
    }
}
