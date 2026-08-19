<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Debug;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Debug\ChatDebugLogger;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Debug\ChatDebugTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChatDebugLogger::class)]
final class ChatDebugLoggerTest extends TestCase
{
    public function testRecordsEveryTraceFieldRegardlessOfHowFarThePipelineGot(): void
    {
        $trace = new ChatDebugTrace('show me jackets under $40');
        $trace->inScope = true;
        $trace->scopeReasonCode = null;
        $trace->retrievalQuery = 'show me jackets under $40';
        $trace->candidates = [
            ['sku' => 'JACKET-1', 'bm25_score' => 4.2, 'vector_score' => 0.81, 'rank_score' => 1.5],
        ];
        $trace->availabilityFilterBeforeCount = 3;
        $trace->availabilityFilterAfterCount = 2;
        $trace->availabilityFilterDroppedSkus = ['JACKET-3'];
        $trace->priceConstraint = ['max' => 40.0, 'max_inclusive' => false, 'min' => null, 'min_inclusive' => true];
        $trace->priceConstraintAddedSkus = ['JACKET-2'];
        $trace->priceConstraintRemovedSkus = [];
        $trace->carriedOverSkus = ['JACKET-4'];
        $trace->finalProductSkus = ['JACKET-1'];
        $trace->outcome = 'generated';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with('chat request trace', [
                'store_id' => 7,
                'conversation_id' => 'conv-123',
                'message' => 'show me jackets under $40',
                'scope' => [
                    'in_scope' => true,
                    'reason_code' => null,
                ],
                'retrieval' => [
                    'query' => 'show me jackets under $40',
                    'candidates' => [
                        ['sku' => 'JACKET-1', 'bm25_score' => 4.2, 'vector_score' => 0.81, 'rank_score' => 1.5],
                    ],
                ],
                'availability_filter' => [
                    'before_count' => 3,
                    'after_count' => 2,
                    'dropped_skus' => ['JACKET-3'],
                ],
                'price_constraint' => [
                    'detected' => ['max' => 40.0, 'max_inclusive' => false, 'min' => null, 'min_inclusive' => true],
                    'added_skus' => ['JACKET-2'],
                    'removed_skus' => [],
                ],
                'carried_over_skus' => ['JACKET-4'],
                'final_product_skus' => ['JACKET-1'],
                'outcome' => 'generated',
            ]);

        (new ChatDebugLogger($logger))->record(7, 'conv-123', $trace);
    }

    public function testAShortCircuitedRequestLogsNullFieldsForStagesNeverReached(): void
    {
        $trace = new ChatDebugTrace('anything at all');
        $trace->outcome = 'assistant_disabled';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with('chat request trace', self::callback(static function (array $context): bool {
                return $context['message'] === 'anything at all'
                    && $context['scope']['in_scope'] === null
                    && $context['retrieval']['query'] === null
                    && $context['retrieval']['candidates'] === null
                    && $context['availability_filter']['before_count'] === null
                    && $context['price_constraint']['detected'] === null
                    && $context['price_constraint']['added_skus'] === null
                    && $context['carried_over_skus'] === null
                    && $context['final_product_skus'] === null
                    && $context['outcome'] === 'assistant_disabled';
            }));

        (new ChatDebugLogger($logger))->record(1, null, $trace);
    }
}
