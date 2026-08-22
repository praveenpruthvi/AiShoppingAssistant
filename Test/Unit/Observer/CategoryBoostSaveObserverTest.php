<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Observer;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\CategoryBoostRow;
use Aavirbhava\AiShoppingAssistant\Observer\CategoryBoostSaveObserver;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(CategoryBoostSaveObserver::class)]
final class CategoryBoostSaveObserverTest extends TestCase
{
    private const CATEGORY_ID = 42;

    public function testANewBoostWeightSavesANewBoostWhenNoneExistedBefore(): void
    {
        $request = $this->request([
            'aavirbhava_category_boost_weight' => '0.6',
            'aavirbhava_category_boost_start_date' => '',
            'aavirbhava_category_boost_end_date' => '',
        ]);

        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->with(self::CATEGORY_ID)->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (CategoryBoostRow $row): bool {
                return $row->boostId() === null
                    && $row->categoryId() === self::CATEGORY_ID
                    && $row->boostWeight() === 0.6
                    && $row->isActive() === true;
            }));

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    public function testAResubmittedWeightUpdatesTheExistingBoostRatherThanCreatingADuplicate(): void
    {
        $request = $this->request([
            'aavirbhava_category_boost_weight' => '0.8',
            'aavirbhava_category_boost_start_date' => '',
            'aavirbhava_category_boost_end_date' => '',
        ]);

        $existing = new CategoryBoostRow(7, self::CATEGORY_ID, 0.5, null, null, true);
        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->with(self::CATEGORY_ID)->willReturn($existing);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (CategoryBoostRow $row): bool {
                return $row->boostId() === 7 && $row->boostWeight() === 0.8;
            }));

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    public function testAZeroWeightDeactivatesAnExistingBoostRatherThanDeletingIt(): void
    {
        $request = $this->request(['aavirbhava_category_boost_weight' => '0']);

        $existing = new CategoryBoostRow(7, self::CATEGORY_ID, 0.5, '2026-01-01 00:00:00', null, true);
        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->with(self::CATEGORY_ID)->willReturn($existing);
        $repository->expects(self::never())->method('deleteById');
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (CategoryBoostRow $row): bool {
                return $row->boostId() === 7
                    && $row->isActive() === false
                    // The boost's own configured weight/dates are
                    // preserved across a deactivation — only is_active
                    // flips, matching CategoryBoostRow's own documented
                    // purpose for the flag.
                    && $row->boostWeight() === 0.5
                    && $row->startDate() === '2026-01-01 00:00:00';
            }));

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    public function testAZeroWeightWithNoExistingBoostSavesNothing(): void
    {
        $request = $this->request(['aavirbhava_category_boost_weight' => '0']);

        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->with(self::CATEGORY_ID)->willReturn(null);
        $repository->expects(self::never())->method('save');

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    public function testTheFieldNeverBeingSubmittedAtAllSavesNothing(): void
    {
        // Distinct from an explicit "0" — the boost fieldset was never
        // even part of this particular request (e.g. a save triggered
        // by something other than the real admin edit form).
        $request = $this->request([]);

        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->expects(self::never())->method('findByCategoryId');
        $repository->expects(self::never())->method('save');

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    public function testANewCategoryWithNoIdYetIsIgnored(): void
    {
        $request = $this->request(['aavirbhava_category_boost_weight' => '0.5']);
        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->getMock();
        $category->method('getId')->willReturn(null);

        $this->observer($request, $repository)->execute(
            new Observer(['event' => new Event(['category' => $category])])
        );
    }

    public function testAStartAndEndDateAreNormalizedToFullMysqlDatetimesAndPassedThrough(): void
    {
        $request = $this->request([
            'aavirbhava_category_boost_weight' => '0.5',
            'aavirbhava_category_boost_start_date' => '2026-03-01',
            'aavirbhava_category_boost_end_date' => '2026-03-31',
        ]);

        $repository = $this->createMock(CategoryBoostRepositoryInterface::class);
        $repository->method('findByCategoryId')->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(function (CategoryBoostRow $row): bool {
                return $row->startDate() === '2026-03-01 00:00:00' && $row->endDate() === '2026-03-31 00:00:00';
            }));

        $this->observer($request, $repository)->execute($this->observerEvent());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default
        );

        return $request;
    }

    private function observer(RequestInterface $request, CategoryBoostRepositoryInterface $repository): CategoryBoostSaveObserver
    {
        return new CategoryBoostSaveObserver(
            $request,
            $repository,
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function observerEvent(): Observer
    {
        $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->getMock();
        $category->method('getId')->willReturn(self::CATEGORY_ID);

        return new Observer(['event' => new Event(['category' => $category])]);
    }
}
