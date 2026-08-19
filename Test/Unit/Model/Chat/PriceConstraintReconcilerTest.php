<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraint;
use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraintReconciler;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceConstraintReconciler::class)]
final class PriceConstraintReconcilerTest extends TestCase
{
    private PriceConstraintReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new PriceConstraintReconciler();
    }

    private function product(int $id, string $sku, float $price, ?float $specialPrice = null): RevalidatedProduct
    {
        return new RevalidatedProduct($id, $sku, 'Product ' . $sku, $price, $specialPrice, 'https://example.test/' . $sku, '2026-01-01T00:00:00+00:00');
    }

    private function response(array $products, array $actions = []): AssistantResponse
    {
        return new AssistantResponse(
            'Here are some options.',
            $products,
            [],
            $actions,
            new ResponseMetadata('openai_compatible', 'test-model', false)
        );
    }

    public function testReturnsTheSameResponseUnchangedWhenNoConstraintWasDetected(): void
    {
        $response = $this->response([]);

        $result = $this->reconciler->reconcile(null, $response, []);

        self::assertSame($response, $result->response);
        self::assertSame([], $result->addedSkus);
        self::assertSame([], $result->removedSkus);
    }

    public function testAddsAQualifyingCandidateTheModelDroppedFromProductSkus(): void
    {
        $cheap = $this->product(1, 'JACKET-1', 32.0);
        $mid = $this->product(2, 'JACKET-2', 45.0);
        $expensive = $this->product(3, 'JACKET-3', 72.0);

        // The model only selected JACKET-1, silently dropping JACKET-2
        // even though it also qualifies for "below $60" — the exact bug
        // this class exists to fix.
        $response = $this->response([new ProductResult($cheap, 'A good, affordable option.')]);

        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$cheap, $mid, $expensive]);

        $skus = array_map(static fn (ProductResult $p): string => $p->product->sku, $result->response->products);
        self::assertSame(['JACKET-1', 'JACKET-2'], $skus);
        self::assertSame(['JACKET-2'], $result->addedSkus);
        self::assertSame([], $result->removedSkus);
    }

    public function testRemovesASelectedProductThatDoesNotActuallyQualify(): void
    {
        $cheap = $this->product(1, 'JACKET-1', 32.0);
        $expensive = $this->product(2, 'JACKET-2', 72.0);

        $response = $this->response([
            new ProductResult($cheap, 'Within budget.'),
            new ProductResult($expensive, 'A pricier option.'),
        ]);

        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$cheap, $expensive]);

        $skus = array_map(static fn (ProductResult $p): string => $p->product->sku, $result->response->products);
        self::assertSame(['JACKET-1'], $skus);
        self::assertSame([], $result->addedSkus);
        self::assertSame(['JACKET-2'], $result->removedSkus);
    }

    public function testUsesTheSpecialPriceWhenPresentRatherThanTheRegularPrice(): void
    {
        // Regular price $75 would fail "below $60", but the real special
        // price ($60 or less, e.g. $59) makes it genuinely qualify.
        $onSale = $this->product(1, 'JACKET-1', 75.0, 55.0);

        $response = $this->response([]);
        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$onSale]);

        self::assertSame(['JACKET-1'], $result->addedSkus);
    }

    public function testNoChangeIsAlsoReflectedAsTheSameResponseInstance(): void
    {
        $cheap = $this->product(1, 'JACKET-1', 32.0);
        $response = $this->response([new ProductResult($cheap, 'Within budget.')]);
        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$cheap]);

        self::assertSame($response, $result->response);
        self::assertSame([], $result->addedSkus);
        self::assertSame([], $result->removedSkus);
    }

    public function testPrunesARemovedSkuFromAnActionRatherThanLeavingADanglingReference(): void
    {
        $cheap = $this->product(1, 'JACKET-1', 32.0);
        $expensive = $this->product(2, 'JACKET-2', 72.0);

        $response = $this->response(
            [
                new ProductResult($cheap, 'Within budget.'),
                new ProductResult($expensive, 'A pricier option.'),
            ],
            [new AssistantAction('recommend', ['JACKET-1', 'JACKET-2'])]
        );

        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$cheap, $expensive]);

        self::assertCount(1, $result->response->actions);
        self::assertSame(['JACKET-1'], $result->response->actions[0]->skus);
    }

    public function testDropsAnActionEntirelyWhenEveryOneOfItsSkusWasRemoved(): void
    {
        $expensive = $this->product(1, 'JACKET-2', 72.0);

        $response = $this->response(
            [new ProductResult($expensive, 'A pricier option.')],
            [new AssistantAction('recommend', ['JACKET-2'])]
        );

        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$expensive]);

        self::assertSame([], $result->response->actions);
    }

    public function testAnAddedProductGetsAnHonestCodeGeneratedReason(): void
    {
        $mid = $this->product(1, 'JACKET-2', 45.0);
        $response = $this->response([]);
        $constraint = new PriceConstraint(60.0, false, null, true);

        $result = $this->reconciler->reconcile($constraint, $response, [$mid]);

        self::assertStringContainsString('45.00', $result->response->products[0]->reason);
    }
}
