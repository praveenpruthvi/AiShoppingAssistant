<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryBoostRow::class)]
final class CategoryBoostRowTest extends TestCase
{
    private function row(array $overrides = []): CategoryBoostRow
    {
        $data = array_replace([
            'boostId' => 1,
            'categoryId' => 42,
            'boostWeight' => 0.5,
            'startDate' => null,
            'endDate' => null,
            'isActive' => true,
            'createdAt' => '2026-08-20 00:00:00',
            'updatedAt' => '2026-08-20 00:00:00',
        ], $overrides);

        return new CategoryBoostRow(
            $data['boostId'],
            $data['categoryId'],
            $data['boostWeight'],
            $data['startDate'],
            $data['endDate'],
            $data['isActive'],
            $data['createdAt'],
            $data['updatedAt']
        );
    }

    public function testAcceptsAValidBoost(): void
    {
        $boost = $this->row();

        self::assertSame(1, $boost->boostId());
        self::assertSame(42, $boost->categoryId());
        self::assertSame(0.5, $boost->boostWeight());
        self::assertTrue($boost->isActive());
    }

    public function testAllowsANullBoostIdForANotYetSavedBoost(): void
    {
        $boost = $this->row(['boostId' => null]);

        self::assertNull($boost->boostId());
    }

    public function testRejectsANonPositiveBoostId(): void
    {
        $this->expectException(MerchandisingBoostException::class);

        $this->row(['boostId' => 0]);
    }

    public function testRejectsANonPositiveCategoryId(): void
    {
        $this->expectException(MerchandisingBoostException::class);

        $this->row(['categoryId' => 0]);
    }

    public function testRejectsANegativeBoostWeight(): void
    {
        $this->expectException(MerchandisingBoostException::class);

        $this->row(['boostWeight' => -0.1]);
    }

    public function testRejectsABoostWeightAboveTheMaximum(): void
    {
        $this->expectException(MerchandisingBoostException::class);

        $this->row(['boostWeight' => CategoryBoostRow::MAX_BOOST_WEIGHT + 0.01]);
    }

    public function testAllowsABoostWeightExactlyAtTheMaximum(): void
    {
        $boost = $this->row(['boostWeight' => CategoryBoostRow::MAX_BOOST_WEIGHT]);

        self::assertSame(CategoryBoostRow::MAX_BOOST_WEIGHT, $boost->boostWeight());
    }

    public function testRejectsAnEndDateBeforeTheStartDate(): void
    {
        $this->expectException(MerchandisingBoostException::class);

        $this->row(['startDate' => '2026-08-20 00:00:00', 'endDate' => '2026-08-19 00:00:00']);
    }

    public function testAllowsAnEndDateEqualToTheStartDate(): void
    {
        $boost = $this->row(['startDate' => '2026-08-20 00:00:00', 'endDate' => '2026-08-20 00:00:00']);

        self::assertSame('2026-08-20 00:00:00', $boost->startDate());
        self::assertSame('2026-08-20 00:00:00', $boost->endDate());
    }

    public function testAllowsAnOpenEndedDateRange(): void
    {
        $boost = $this->row(['startDate' => '2026-08-20 00:00:00', 'endDate' => null]);

        self::assertSame('2026-08-20 00:00:00', $boost->startDate());
        self::assertNull($boost->endDate());
    }

    public function testSharesTheSameCapAsMerchandisingBoostRow(): void
    {
        // The two boost sources feed into ONE capped combined total (see
        // MerchandisingBoostSignal's own docblock) — they must share a
        // single cap value, not two independently maintained ones.
        self::assertSame(
            \Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow::MAX_BOOST_WEIGHT,
            CategoryBoostRow::MAX_BOOST_WEIGHT
        );
    }
}
