<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\ActivePromotionReader;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Group;
use Magento\Framework\App\State;
use Magento\SalesRule\Model\Rule as CartRule;
use Magento\SalesRule\Model\Rule\Condition\Combine;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as CartRuleCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ActivePromotionReader::activeCartRules() against the real
 * database — Magento\SalesRule\Model\ResourceModel\Rule\Collection::
 * addWebsiteGroupDateFilter()'s real date/website/customer-group SQL
 * isn't meaningfully re-verifiable against a mocked adapter (the same
 * rationale as DbConversationHistoryStoreDatabaseTest/
 * MerchandisingBoostDatabaseTest). Rules are created via
 * Magento\SalesRule\Model\Rule::save() using the exact real pattern
 * Magento core's own dev/tests/integration SalesRule fixtures use.
 */
final class ActiveCartPromotionDatabaseTest extends TestCase
{
    private const GROUP_A = 0; // NOT LOGGED IN — always exists
    private const GROUP_B = 1; // General — always exists on a stock install

    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private ActivePromotionReader $reader;
    private int $websiteId;

    /**
     * @var list<int>
     */
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 8);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
        $this->objectManager = $bootstrap->getObjectManager();

        try {
            $this->objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->websiteId = (int) $this->objectManager->get(StoreManagerInterface::class)->getWebsite()->getId();

        $this->reader = new ActivePromotionReader(
            $this->objectManager->get(StoreScopeProviderInterface::class),
            new MutableClock(new \DateTimeImmutable('2026-06-15 00:00:00')),
            $this->objectManager->get(CatalogRuleResource::class),
            $this->objectManager->get(CartRuleCollectionFactory::class)
        );

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testAnActiveInRangeRuleForTheRealCustomerGroupIsSurfaced(): void
    {
        $ruleId = $this->createRule(
            'AI Assistant Test — Active Sale',
            [self::GROUP_A],
            CartRule::COUPON_TYPE_NO_COUPON,
            null,
            null
        );

        $result = $this->reader->activeCartRules(1, self::GROUP_A);

        $names = array_map(static fn ($promotion): string => $promotion->name(), $result);
        self::assertContains('AI Assistant Test — Active Sale', $names);

        $ids = array_map(static fn ($promotion): int => $promotion->ruleId(), $result);
        self::assertContains($ruleId, $ids);
    }

    public function testARuleOutsideItsActiveDateRangeDoesNotSurface(): void
    {
        $this->createRule(
            'AI Assistant Test — Expired Sale',
            [self::GROUP_A],
            CartRule::COUPON_TYPE_NO_COUPON,
            '2020-01-01',
            '2020-01-31'
        );

        $result = $this->reader->activeCartRules(1, self::GROUP_A);

        $names = array_map(static fn ($promotion): string => $promotion->name(), $result);
        self::assertNotContains('AI Assistant Test — Expired Sale', $names);
    }

    public function testARuleForADifferentCustomerGroupDoesNotLeakIntoAnotherGroupsResult(): void
    {
        $this->createRule(
            'AI Assistant Test — Group A Only Sale',
            [self::GROUP_A],
            CartRule::COUPON_TYPE_NO_COUPON,
            null,
            null
        );

        $resultForGroupB = $this->reader->activeCartRules(1, self::GROUP_B);

        $names = array_map(static fn ($promotion): string => $promotion->name(), $resultForGroupB);
        self::assertNotContains('AI Assistant Test — Group A Only Sale', $names);
    }

    public function testACouponRequiredRuleReportsItsRealCode(): void
    {
        $ruleId = $this->createRule(
            'AI Assistant Test — Coupon Sale',
            [self::GROUP_A],
            CartRule::COUPON_TYPE_SPECIFIC,
            null,
            null,
            'AITESTCODE'
        );

        $result = $this->reader->activeCartRules(1, self::GROUP_A);

        $match = null;
        foreach ($result as $promotion) {
            if ($promotion->ruleId() === $ruleId) {
                $match = $promotion;
            }
        }

        self::assertNotNull($match);
        self::assertTrue($match->requiresCoupon());
        self::assertSame('AITESTCODE', $match->couponCode());
    }

    /**
     * @param list<int> $customerGroupIds
     */
    private function createRule(
        string $name,
        array $customerGroupIds,
        int $couponType,
        ?string $fromDate,
        ?string $toDate,
        ?string $couponCode = null
    ): int {
        /** @var CartRule $rule */
        $rule = $this->objectManager->create(CartRule::class);
        $rule->setData([
            'name' => $name,
            'is_active' => 1,
            'customer_group_ids' => $customerGroupIds,
            'website_ids' => [$this->websiteId],
            'coupon_type' => $couponType,
            'simple_action' => CartRule::BY_PERCENT_ACTION,
            'discount_amount' => 10,
            'discount_step' => 0,
            'stop_rules_processing' => 0,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        if ($couponCode !== null) {
            $rule->setCouponCode($couponCode);
        }

        $rule->getConditions()->loadArray([
            'type' => Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'aggregator' => 'all',
            'conditions' => [],
        ]);

        $rule->save();

        $ruleId = (int) $rule->getId();
        $this->createdRuleIds[] = $ruleId;

        return $ruleId;
    }

    private function cleanup(): void
    {
        if ($this->createdRuleIds === []) {
            return;
        }

        $resource = $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $connection = $resource->getConnection();

        foreach (['salesrule_customer_group', 'salesrule_website', 'salesrule_coupon', 'salesrule'] as $table) {
            $tableName = $resource->getTableName($table);
            $connection->delete($tableName, ['rule_id IN (?)' => $this->createdRuleIds]);
        }

        $this->createdRuleIds = [];
    }
}
