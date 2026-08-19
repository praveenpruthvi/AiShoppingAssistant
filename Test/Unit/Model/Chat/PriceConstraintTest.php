<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceConstraint::class)]
final class PriceConstraintTest extends TestCase
{
    public function testExclusiveMaxRejectsAPriceExactlyAtTheThreshold(): void
    {
        $constraint = new PriceConstraint(60.0, false, null, true);

        self::assertTrue($constraint->isSatisfiedBy(59.99));
        self::assertFalse($constraint->isSatisfiedBy(60.0));
        self::assertFalse($constraint->isSatisfiedBy(60.01));
    }

    public function testInclusiveMaxAcceptsAPriceExactlyAtTheThreshold(): void
    {
        $constraint = new PriceConstraint(60.0, true, null, true);

        self::assertTrue($constraint->isSatisfiedBy(60.0));
        self::assertFalse($constraint->isSatisfiedBy(60.01));
    }

    public function testExclusiveMinRejectsAPriceExactlyAtTheThreshold(): void
    {
        $constraint = new PriceConstraint(null, true, 20.0, false);

        self::assertFalse($constraint->isSatisfiedBy(20.0));
        self::assertTrue($constraint->isSatisfiedBy(20.01));
    }

    public function testInclusiveMinAcceptsAPriceExactlyAtTheThreshold(): void
    {
        $constraint = new PriceConstraint(null, true, 20.0, true);

        self::assertTrue($constraint->isSatisfiedBy(20.0));
        self::assertFalse($constraint->isSatisfiedBy(19.99));
    }

    public function testARangeRequiresBothBoundsSatisfied(): void
    {
        $constraint = new PriceConstraint(60.0, true, 20.0, true);

        self::assertTrue($constraint->isSatisfiedBy(40.0));
        self::assertFalse($constraint->isSatisfiedBy(10.0));
        self::assertFalse($constraint->isSatisfiedBy(70.0));
    }

    public function testRejectsNeitherBoundProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceConstraint(null, true, null, true);
    }

    public function testRejectsANegativeMaxBound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceConstraint(-1.0, true, null, true);
    }

    public function testRejectsANegativeMinBound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceConstraint(null, true, -1.0, true);
    }

    public function testRejectsAMinBoundAboveTheMaxBound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceConstraint(20.0, true, 60.0, true);
    }
}
