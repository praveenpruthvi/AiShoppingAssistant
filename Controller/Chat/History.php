<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Controller\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ConversationHistoryViewBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Session\ChatSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /aichat/chat/history — restores the widget's visible transcript
 * after a page reload or in a new tab, so a conversation isn't silently
 * erased just because the page was. Works because ChatSession's
 * conversationId already lives in Magento's own session cookie (Task 8) —
 * the same cookie is shared by every tab of the same browser, so this
 * naturally covers a reload in the current window and a fresh tab opened
 * from a product link (the one this feature was requested alongside)
 * equally, with no extra client-side coordination needed.
 *
 * Deliberately reads ChatSession::getConversationId() directly rather
 * than going through ChatIdentityResolverInterface::resolve() (Task 8) —
 * resolve() allocates a fresh conversation id AND may auto-vivify a guest
 * quote as a side effect, neither of which a passive "is there anything to
 * restore" check on every page load should ever trigger for a visitor who
 * has never opened the chat widget yet. No conversation id yet means
 * nothing to restore, by definition — an empty list, not an error.
 *
 * Never allowed to break page load for a chat feature that is itself just
 * a convenience: any failure anywhere in this flow (config read, store
 * resolution, storage) degrades to an empty transcript, exactly like a
 * customer who has never chatted before — never a 5xx a caller has to
 * handle specially.
 *
 * Each restored message now also carries its persisted
 * products/follow_up_questions/actions (Task 20) — the same shape a live
 * turn's response already carries, so the widget renders a restored
 * turn's product cards through the identical code a live turn uses.
 */
class History implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ChatSession $chatSession,
        private readonly ConversationHistoryViewBuilder $historyViewBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        return $this->json($this->messages());
    }

    /**
     * @return list<array{role: string, message: string, products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}>
     */
    private function messages(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $general = $this->configurationReader->readGeneral($storeId);

            if (!$general->isEnabled()) {
                return [];
            }

            $conversationId = $this->chatSession->getConversationId();

            if ($conversationId === null) {
                return [];
            }

            return $this->historyViewBuilder->build($conversationId, $storeId, $general->maxConversationMessages());
        } catch (Throwable $exception) {
            $this->logger->error('AI shopping assistant: conversation history restore failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param list<array{role: string, message: string}> $messages
     */
    private function json(array $messages): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();
        $result->setData(['messages' => $messages]);

        return $result;
    }
}
