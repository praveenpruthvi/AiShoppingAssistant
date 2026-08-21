<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostRepositoryInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\ProviderCost\Save;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCalculator;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for a real discrepancy surfaced between two claims in
 * Task 41's own status report: a real controller Save persisting
 * google=0.00125/0.005 "for real," and a later CostCalculator sweep
 * reportedly showing google=$0.0000 in the SAME report. Direct
 * verification (Task 42) found no actual bug — a fresh, single-process
 * trace (real repository read, then a real controller Save immediately
 * followed by a real CostCalculator read) picked up the saved price
 * correctly every time, with no manual cache-clear anywhere in the path.
 * This test locks that real, correct behavior in as a permanent
 * regression guard, using the real admin Save controller (not just the
 * repository it delegates to) so a future regression in either layer —
 * the controller's own validation/redirect handling, or a caching layer
 * introduced later in ConfigurationReader/CostCalculator's read path —
 * would be caught here.
 *
 * Uses the real `xai` provider identifier (a real, already-registered
 * provider — the controller's own LlmProviderRegistryInterface::has()
 * check rejects anything else) rather than a test-namespaced one, and
 * restores its real pre-test price in tearDown() so this test can never
 * leave this store's actual xai pricing altered.
 */
final class ProviderCostSaveIsImmediatelyReadableTest extends TestCase
{
    private const TEST_INPUT_PRICE = '0.00777';
    private const TEST_OUTPUT_PRICE = '0.00999';

    private ObjectManagerInterface $objectManager;

    private ProviderCostRepositoryInterface $repository;

    /**
     * @var array{input: float, output: float}
     */
    private array $originalXaiPrice;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 8);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = Bootstrap::create($root, $_SERVER);
        $this->objectManager = $bootstrap->getObjectManager();

        try {
            $this->objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->repository = $this->objectManager->get(ProviderCostRepositoryInterface::class);
        $all = $this->repository->all();
        $this->originalXaiPrice = $all[ProviderIdentifiers::LLM_XAI] ?? ['input' => 0.0, 'output' => 0.0];
    }

    protected function tearDown(): void
    {
        $this->repository->setPrice(
            ProviderIdentifiers::LLM_XAI,
            $this->originalXaiPrice['input'],
            $this->originalXaiPrice['output']
        );
    }

    public function testARealControllerSaveIsImmediatelyPickedUpByCostCalculatorWithNoCacheClear(): void
    {
        $request = $this->objectManager->get(RequestInterface::class);
        $request->setParams([
            'provider' => ProviderIdentifiers::LLM_XAI,
            'price_per_1k_input_tokens' => self::TEST_INPUT_PRICE,
            'price_per_1k_output_tokens' => self::TEST_OUTPUT_PRICE,
        ]);

        $controller = $this->objectManager->create(Save::class, [
            'context' => $this->objectManager->get(Context::class),
        ]);

        $controller->execute();

        // Same process, no cache:flush, no new ConfigurationReader
        // instance forced — the exact scenario Task 41's report claimed
        // (incorrectly, per this test) could go stale.
        $configurationReader = $this->objectManager->get(ConfigurationReaderInterface::class);
        $providerCost = $configurationReader->readProviderCost(1);

        self::assertSame(
            (float) self::TEST_INPUT_PRICE,
            $providerCost->pricePerThousandInputTokens(ProviderIdentifiers::LLM_XAI)
        );
        self::assertSame(
            (float) self::TEST_OUTPUT_PRICE,
            $providerCost->pricePerThousandOutputTokens(ProviderIdentifiers::LLM_XAI)
        );

        $calculator = $this->objectManager->get(CostCalculator::class);
        $cost = $calculator->cost(new TokenUsage(1000, 1000), ProviderIdentifiers::LLM_XAI, $providerCost);

        $expected = (float) self::TEST_INPUT_PRICE + (float) self::TEST_OUTPUT_PRICE;
        self::assertEqualsWithDelta($expected, $cost, 0.0000001);
    }
}
