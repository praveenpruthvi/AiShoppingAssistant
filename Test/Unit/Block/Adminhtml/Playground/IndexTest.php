<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\Playground\Index;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\Index as IndexController;
use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundResult;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Block is a pure view: it only ever reads whatever the
 * Controller registered (never re-runs or re-derives anything), plus the
 * small view-formatting helpers the template relies on.
 *
 * Magento\Backend\Block\Template's own constructor falls back to the
 * static \Magento\Framework\App\ObjectManager::getInstance() for a couple
 * of optional collaborators (e.g. the escaper) when Context's auto-mocked
 * getters return null — the standard Magento core pattern for unit-
 * testing a Template subclass is to prime that static singleton in
 * setUp(), which is all this does; it is torn back down in tearDown() so
 * it never leaks into another test file.
 */
#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $type) => $type === Escaper::class ? new Escaper() : $this->createMock($type)
        );
        AppObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(AppObjectManager::class, '_instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    private function candidate(int $entityId, string $sku, float $bm25, float $vector, float $score = 0.0): SearchCandidate
    {
        return new SearchCandidate($entityId, $sku, 1, 'Name ' . $sku, '', [], [], true, 4, $bm25, $vector, $score);
    }

    public function testReadsNothingRegisteredAsEmptyDefaults(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->willReturn(null);

        $block = $this->block($registry);

        self::assertSame('', $block->getSubmittedQuery());
        self::assertFalse($block->wasLlmRequested());
        self::assertNull($block->getError());
        self::assertNull($block->getResult());
    }

    public function testReadsWhateverTheControllerRegistered(): void
    {
        // PlaygroundResult is final (deliberately — see its own docblock)
        // so it cannot be mocked; a minimal real instance is constructed
        // instead.
        $result = new PlaygroundResult(
            'red hats',
            1,
            true,
            null,
            [],
            [],
            [],
            false,
            [],
            [],
            null,
            false,
            [],
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null
        );

        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->willReturnMap([
            [IndexController::REGISTRY_KEY_QUERY, 'red hats'],
            [IndexController::REGISTRY_KEY_CALL_LLM, true],
            [IndexController::REGISTRY_KEY_ERROR, 'something went wrong'],
            [IndexController::REGISTRY_KEY_RESULT, $result],
        ]);

        $block = $this->block($registry);

        self::assertSame('red hats', $block->getSubmittedQuery());
        self::assertTrue($block->wasLlmRequested());
        self::assertSame('something went wrong', $block->getError());
        self::assertSame($result, $block->getResult());
    }

    public function testGetSortedByBm25OrdersDescendingAndExcludesZeroScores(): void
    {
        $block = $this->block();

        $candidates = [
            $this->candidate(1, 'SKU-1', 0.0, 5.0),
            $this->candidate(2, 'SKU-2', 3.0, 0.0),
            $this->candidate(3, 'SKU-3', 7.0, 0.0),
        ];

        $sorted = $block->getSortedByBm25($candidates);

        self::assertSame(['SKU-3', 'SKU-2'], array_map(static fn (SearchCandidate $c): string => $c->sku, $sorted));
    }

    public function testGetSortedByVectorOrdersDescendingAndExcludesZeroScores(): void
    {
        $block = $this->block();

        $candidates = [
            $this->candidate(1, 'SKU-1', 5.0, 0.0),
            $this->candidate(2, 'SKU-2', 0.0, 1.0),
            $this->candidate(3, 'SKU-3', 0.0, 9.0),
        ];

        $sorted = $block->getSortedByVector($candidates);

        self::assertSame(['SKU-3', 'SKU-2'], array_map(static fn (SearchCandidate $c): string => $c->sku, $sorted));
    }

    public function testCandidateTableHtmlContainsEachCandidatesSkuAndScore(): void
    {
        $block = $this->block();

        $html = $block->getCandidateTableHtml([$this->candidate(1, 'SKU-1', 0.0, 0.0, 3.5)], 'score');

        self::assertStringContainsString('SKU-1', $html);
        self::assertStringContainsString('3.5000', $html);
    }

    public function testCandidateTableHtmlHandlesNoCandidates(): void
    {
        $block = $this->block();

        self::assertStringContainsString('No candidates', $block->getCandidateTableHtml([], 'score'));
    }

    public function testJsonPrettyProducesValidReadableJson(): void
    {
        $block = $this->block();

        $json = $block->jsonPretty(['sku' => 'SKU-1', 'qty' => 2]);

        self::assertSame(['sku' => 'SKU-1', 'qty' => 2], json_decode($json, true));
        self::assertStringContainsString("\n", $json);
    }

    private function block(?Registry $registry = null): Index
    {
        $objectManager = new ObjectManager($this);

        // Context is auto-mocked below Index, but escapeHtml() needs a
        // *real* Escaper behind it (a mocked one returns '' for every
        // call), so Context is built explicitly with one.
        $context = $objectManager->getObject(Context::class, [
            'escaper' => new Escaper(),
        ]);

        return $objectManager->getObject(Index::class, [
            'context' => $context,
            'registry' => $registry ?? $this->createMock(Registry::class),
        ]);
    }
}
