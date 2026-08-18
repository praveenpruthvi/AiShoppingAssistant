<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatPipelineResult;

/**
 * The full runtime request pipeline: Input Validation -> Commerce Scope
 * Classifier -> fixed safe response for out-of-scope messages, or
 * retrieval + ranking -> live revalidation -> ToolCallingChatServiceInterface
 * (structured-output product context plus prior conversation turns,
 * allowlisted commerce tools, the tool-call round-trip) -> Output
 * Validator -> structured response contract or fixed safe response.
 *
 * $customerGroupId/$cartId are optional because not every caller resolves
 * a real one (e.g. a direct/CLI/test invocation) — null resolves to
 * Magento's NOT_LOGGED_IN group / "no cart available" downstream, exactly
 * as before Task 8. A real storefront request (Controller\Chat\Send)
 * resolves both from Magento's own session via ChatIdentityResolverInterface.
 *
 * $conversationId (Task 8) is the key prior turns are stored and loaded
 * under (see ConversationHistoryStoreInterface) — null means "no memory,"
 * exactly the single-message-per-call behavior this pipeline had before
 * Task 8, still the correct behavior for a caller with no real session.
 */
interface ChatEntryPipelineInterface
{
    public function handle(
        int $storeId,
        string $rawMessage,
        ?int $customerGroupId = null,
        ?string $cartId = null,
        ?string $conversationId = null
    ): ChatPipelineResult;
}
