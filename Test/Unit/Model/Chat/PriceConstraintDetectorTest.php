<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraintDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceConstraintDetector::class)]
final class PriceConstraintDetectorTest extends TestCase
{
    private PriceConstraintDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new PriceConstraintDetector();
    }

    public function testNoConstraintWhenNoPriceIsMentioned(): void
    {
        self::assertNull($this->detector->detect('show me some jackets'));
    }

    public function testUnderIsAnExclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('find me jackets below $60');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertFalse($constraint->maxInclusive);
        self::assertNull($constraint->min);
    }

    public function testUnderPhrasingIsAlsoAnExclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('show me jackets under $40');

        self::assertNotNull($constraint);
        self::assertSame(40.0, $constraint->max);
        self::assertFalse($constraint->maxInclusive);
    }

    public function testUpToIsAnInclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('jackets up to $60');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
    }

    public function testTrailingOrLessIsAnInclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('jackets for $60 or less');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
    }

    public function testOverIsAnExclusiveMinBound(): void
    {
        $constraint = $this->detector->detect('something over $20');

        self::assertNotNull($constraint);
        self::assertSame(20.0, $constraint->min);
        self::assertFalse($constraint->minInclusive);
        self::assertNull($constraint->max);
    }

    public function testAtLeastIsAnInclusiveMinBound(): void
    {
        $constraint = $this->detector->detect('something at least $20');

        self::assertNotNull($constraint);
        self::assertSame(20.0, $constraint->min);
        self::assertTrue($constraint->minInclusive);
    }

    public function testBetweenDetectsBothBoundsInclusively(): void
    {
        $constraint = $this->detector->detect('jackets between $20 and $60');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
        self::assertSame(20.0, $constraint->min);
        self::assertTrue($constraint->minInclusive);
    }

    public function testDollarsWordFormIsRecognizedJustLikeTheDollarSign(): void
    {
        $constraint = $this->detector->detect('jackets under 40 dollars');

        self::assertNotNull($constraint);
        self::assertSame(40.0, $constraint->max);
    }

    public function testABarePriceWithNoThresholdWordIsNotAConstraint(): void
    {
        self::assertNull($this->detector->detect('this jacket is $60'));
    }

    public function testACommaSeparatedThousandsPriceIsParsedCorrectly(): void
    {
        $constraint = $this->detector->detect('something under $1,200');

        self::assertNotNull($constraint);
        self::assertSame(1200.0, $constraint->max);
    }

    public function testWithinIsAnInclusiveMaxBound(): void
    {
        // Live-reproduced gap (Task 27): "show me price within $50"
        // previously detected no constraint at all.
        $constraint = $this->detector->detect('show me price within $50');

        self::assertNotNull($constraint);
        self::assertSame(50.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
        self::assertNull($constraint->min);
    }

    public function testBudgetOfIsAnInclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('jackets, budget of $50');

        self::assertNotNull($constraint);
        self::assertSame(50.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
    }

    public function testTrailingBudgetIsAnInclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('jackets, $50 budget');

        self::assertNotNull($constraint);
        self::assertSame(50.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
    }

    public function testTrailingOrUnderIsAnInclusiveMaxBound(): void
    {
        $constraint = $this->detector->detect('jackets for $60 or under');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
    }

    public function testAroundIsASymmetricInclusiveRangeNotASingleMaxBound(): void
    {
        $constraint = $this->detector->detect('something around $50');

        self::assertNotNull($constraint);
        self::assertSame(60.0, $constraint->max);
        self::assertTrue($constraint->maxInclusive);
        self::assertSame(40.0, $constraint->min);
        self::assertTrue($constraint->minInclusive);
    }

    public function testAroundIncludesAPriceModestlyAboveTheStatedFigure(): void
    {
        // The whole point of treating "around" as a range rather than a
        // max bound: a customer asking for something around $50 would
        // still expect a genuinely close $55 item to show up.
        $constraint = $this->detector->detect('something around $50');

        self::assertNotNull($constraint);
        self::assertTrue($constraint->isSatisfiedBy(55.0));
    }

    public function testAboutIsNotTreatedAsAPriceApproximation(): void
    {
        // Deliberately not covered: "about" collides with its far more
        // common non-price sense ("tell me about $50 gift cards").
        self::assertNull($this->detector->detect('tell me about $50 gift cards'));
    }
}
