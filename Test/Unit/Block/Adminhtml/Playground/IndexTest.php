<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\Playground\Index;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\Index as IndexController;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\OutputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use Aavirbhava\AiShoppingAssistant\Model\Chat\SafeResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundResult;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
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

    public function testGetCollapsibleInitJsonProducesValidMageCollapsibleConfig(): void
    {
        $block = $this->block();

        $decoded = json_decode($block->getCollapsibleInitJson(true), true);

        self::assertTrue($decoded['collapsible']['active']);
        self::assertSame('_show', $decoded['collapsible']['openedState']);
        self::assertSame('_hide', $decoded['collapsible']['closedState']);
        self::assertTrue($decoded['collapsible']['collapsible']);

        $decodedClosed = json_decode($block->getCollapsibleInitJson(false), true);
        self::assertFalse($decodedClosed['collapsible']['active']);
    }

    public function testGetScopeBadgeReflectsInScope(): void
    {
        $block = $this->block();

        $inScope = $block->getScopeBadge($this->playgroundResult(['inScope' => true]));
        self::assertSame('success', $inScope['type']);

        $outOfScope = $block->getScopeBadge($this->playgroundResult(['inScope' => false]));
        self::assertSame('error', $outOfScope['type']);
    }

    public function testGetBadgeHtmlEscapesTheLabelAndAppliesTheTypeClass(): void
    {
        $block = $this->block();

        $html = $block->getBadgeHtml('<script>alert(1)</script>', 'success');

        self::assertStringContainsString('message-success', $html);
        self::assertStringContainsString('success', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGetBadgeHtmlFallsBackToNoticeForAnUnknownType(): void
    {
        $block = $this->block();

        $html = $block->getBadgeHtml('Something', 'not-a-real-type');

        self::assertStringContainsString('message-notice', $html);
    }

    public function testGetFallbackBadgeIsNotRunWhenNoRoundEverCompleted(): void
    {
        $block = $this->block();

        $badge = $block->getFallbackBadge($this->playgroundResult(['llmRounds' => []]));

        self::assertSame('notice', $badge['type']);
    }

    public function testGetFallbackBadgeReflectsTheLastRoundsUsedFallbackFlag(): void
    {
        $block = $this->block();

        $noFallback = $block->getFallbackBadge($this->playgroundResult([
            'llmRounds' => [$this->chatResponseRound(false)],
        ]));
        self::assertSame('success', $noFallback['type']);

        $usedFallback = $block->getFallbackBadge($this->playgroundResult([
            'llmRounds' => [$this->chatResponseRound(true)],
        ]));
        self::assertSame('warning', $usedFallback['type']);
    }

    public function testGetValidationCheckBadgesAreAllNotRunWhenTheLlmWasNeverCalled(): void
    {
        $block = $this->block();

        $badges = $block->getValidationCheckBadges($this->playgroundResult(['llmWasCalled' => false]));

        self::assertCount(4, $badges);
        foreach ($badges as $badge) {
            self::assertSame('notice', $badge['type']);
        }
    }

    public function testGetValidationCheckBadgesAreAllNotRunOnAProviderError(): void
    {
        $block = $this->block();

        $badges = $block->getValidationCheckBadges($this->playgroundResult([
            'llmWasCalled' => true,
            'llmError' => 'PROVIDER_TIMEOUT',
        ]));

        foreach ($badges as $badge) {
            self::assertSame('notice', $badge['type']);
        }
    }

    public function testGetValidationCheckBadgesAreAllPassedWhenTheResponseWasValid(): void
    {
        $block = $this->block();

        $badges = $block->getValidationCheckBadges($this->playgroundResult([
            'llmWasCalled' => true,
            'finalResponse' => $this->assistantResponse(),
        ]));

        self::assertCount(4, $badges);
        foreach ($badges as $badge) {
            self::assertSame('success', $badge['type']);
        }
    }

    public function testGetValidationCheckBadgesFlagsExactlyTheFailingCheckAndMarksTheRestNotRun(): void
    {
        $block = $this->block();

        $badges = $block->getValidationCheckBadges($this->playgroundResult([
            'llmWasCalled' => true,
            'safeResponse' => new SafeResponse('rejected', OutputValidator::REASON_FABRICATED_SKU),
        ]));

        $byCode = [];
        foreach ($badges as $badge) {
            $byCode[$badge['code']] = $badge['type'];
        }

        self::assertSame('error', $byCode[OutputValidator::REASON_FABRICATED_SKU]);
        self::assertSame('notice', $byCode[OutputValidator::REASON_FABRICATED_PRICE]);
        self::assertSame('notice', $byCode[OutputValidator::REASON_FABRICATED_URL]);
        self::assertSame('notice', $byCode[OutputValidator::REASON_MALFORMED_RESPONSE]);
    }

    public function testGetFinalResponseJsonReturnsNullWhenNeitherResponseExists(): void
    {
        $block = $this->block();

        self::assertNull($block->getFinalResponseJson($this->playgroundResult()));
    }

    public function testGetFinalResponseJsonMirrorsTheRealAssistantResponseShape(): void
    {
        $block = $this->block();

        $json = $block->getFinalResponseJson($this->playgroundResult([
            'finalResponse' => $this->assistantResponse(true),
        ]));

        $decoded = json_decode($json, true);

        self::assertSame('Here is a widget.', $decoded['message']);
        self::assertNull($decoded['reason_code']);
        self::assertCount(1, $decoded['products']);
        self::assertSame('SKU-1', $decoded['products'][0]['sku']);
        self::assertSame('matches your search', $decoded['products'][0]['reason']);
        self::assertSame([], $decoded['follow_up_questions']);
        self::assertSame([], $decoded['actions']);
        self::assertTrue($decoded['metadata']['fallback_used']);
    }

    public function testGetFinalResponseJsonMirrorsTheSafeResponseShape(): void
    {
        $block = $this->block();

        $json = $block->getFinalResponseJson($this->playgroundResult([
            'safeResponse' => new SafeResponse('rejected', OutputValidator::REASON_FABRICATED_URL),
        ]));

        $decoded = json_decode($json, true);

        self::assertSame('rejected', $decoded['message']);
        self::assertSame(OutputValidator::REASON_FABRICATED_URL, $decoded['reason_code']);
        self::assertSame([], $decoded['products']);
        self::assertNull($decoded['metadata']);
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
            // ChatResponseSerializer is declared final, so PHPUnit's
            // createMock() cannot double it (confirmed live — attempting
            // to mock it throws ClassIsFinalException) — a real instance
            // is used instead, matching this module's existing precedent
            // for pure, dependency-free collaborators (see
            // ConfigurationReaderTest's own use of a real ColorContrast).
            'responseSerializer' => new ChatResponseSerializer(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function playgroundResult(array $overrides = []): PlaygroundResult
    {
        $defaults = [
            'queryText' => 'red hats',
            'storeId' => 1,
            'inScope' => true,
            'scopeReasonCode' => null,
            'retrievedCandidates' => [],
            'rankingStages' => [],
            'rankedCandidates' => [],
            'rerankerConfigured' => false,
            'revalidationOutcomes' => [],
            'verifiedProducts' => [],
            'productContextText' => null,
            'llmWasCalled' => false,
            'llmRounds' => [],
            'toolExecutions' => [],
            'finalResponse' => null,
            'safeResponse' => null,
            'llmError' => null,
            'llmProvider' => null,
            'llmModel' => null,
            'totalInputTokens' => null,
            'totalOutputTokens' => null,
            'totalCachedTokens' => null,
            'totalLatencyMilliseconds' => null,
        ];
        $data = array_replace($defaults, $overrides);

        return new PlaygroundResult(
            $data['queryText'],
            $data['storeId'],
            $data['inScope'],
            $data['scopeReasonCode'],
            $data['retrievedCandidates'],
            $data['rankingStages'],
            $data['rankedCandidates'],
            $data['rerankerConfigured'],
            $data['revalidationOutcomes'],
            $data['verifiedProducts'],
            $data['productContextText'],
            $data['llmWasCalled'],
            $data['llmRounds'],
            $data['toolExecutions'],
            $data['finalResponse'],
            $data['safeResponse'],
            $data['llmError'],
            $data['llmProvider'],
            $data['llmModel'],
            $data['totalInputTokens'],
            $data['totalOutputTokens'],
            $data['totalCachedTokens'],
            $data['totalLatencyMilliseconds']
        );
    }

    private function chatResponseRound(bool $usedFallback): array
    {
        return [
            'round' => 1,
            'response' => new ChatResponse(
                'hi',
                [],
                new TokenUsage(10, 5),
                'openai',
                'gpt-test',
                100,
                $usedFallback
            ),
        ];
    }

    private function assistantResponse(bool $fallbackUsed = false): AssistantResponse
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Widget', 19.99, null, 'https://example.test/w', '2026-01-01T00:00:00+00:00');

        return new AssistantResponse(
            'Here is a widget.',
            [new ProductResult($product, 'matches your search')],
            [],
            [],
            new ResponseMetadata('openai', 'gpt-test', $fallbackUsed)
        );
    }
}
