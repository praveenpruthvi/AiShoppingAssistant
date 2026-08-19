<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Debug;

use Psr\Log\LoggerInterface;

/**
 * Writes one compact, always-on trace entry per real chat request to a
 * dedicated log file (etc/di.xml wires the injected LoggerInterface to its
 * own Monolog channel/handler, separate from system.log) — this is a
 * request-tracing aid, not an error/incident log, so it logs every request
 * regardless of outcome, not just failures.
 *
 * Every field taken from ChatDebugTrace is already a store-scoped fact this
 * module logs elsewhere too (retrieval scores, SKUs, reason codes) — never
 * raw provider request/response bodies, and never anything not already
 * considered safe to log by this codebase's existing logging call sites.
 *
 * Logs at PSR debug() level deliberately, not info(): etc/di.xml's
 * dedicated Logger virtualType only overrides the "debug" item of
 * Magento\Framework\Logger\Monolog's default three-handler array (system/
 * debug/syslog) — Magento's own DI merges a virtualType's array argument
 * with its base type's by item key, so the inherited "system" slot
 * (Handler\System, threshold Logger::INFO) stays attached unless a
 * logged record's level is actually below it. An info()-level call was
 * live-verified to leak into system.log for exactly this reason; debug()
 * (Logger::DEBUG, below Handler\System's INFO floor) does not. This is
 * the same "debug" key + debug()-level combination
 * Magento_Payment/Magento_Shipping's own virtual debug loggers already
 * rely on for the same isolation.
 */
final class ChatDebugLogger
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(int $storeId, ?string $conversationId, ChatDebugTrace $trace): void
    {
        $this->logger->debug('chat request trace', [
            'store_id' => $storeId,
            'conversation_id' => $conversationId,
            'message' => $trace->message,
            'scope' => [
                'in_scope' => $trace->inScope,
                'reason_code' => $trace->scopeReasonCode,
            ],
            'retrieval' => [
                'query' => $trace->retrievalQuery,
                'candidates' => $trace->candidates,
            ],
            'availability_filter' => [
                'before_count' => $trace->availabilityFilterBeforeCount,
                'after_count' => $trace->availabilityFilterAfterCount,
                'dropped_skus' => $trace->availabilityFilterDroppedSkus,
            ],
            'price_constraint' => [
                'detected' => $trace->priceConstraint,
                'added_skus' => $trace->priceConstraintAddedSkus,
                'removed_skus' => $trace->priceConstraintRemovedSkus,
            ],
            'carried_over_skus' => $trace->carriedOverSkus,
            'final_product_skus' => $trace->finalProductSkus,
            'outcome' => $trace->outcome,
        ]);
    }
}
