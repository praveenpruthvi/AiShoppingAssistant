<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Session;

/**
 * Dedicated frontend PHP session namespace for the assistant, mirroring
 * Magento\Checkout\Model\Session / Magento\Customer\Model\Session exactly:
 * a thin subclass of Magento\Framework\Session\SessionManager whose only
 * purpose is to get its own storage namespace. This is NOT optional
 * boilerplate — Magento\Framework\Session\Storage defaults to the shared
 * "default" namespace unless a class gets its own virtualType override
 * (see etc/di.xml), so skipping this step would silently share $_SESSION
 * state with any other unconfigured session consumer.
 *
 * Holds exactly one thing: an opaque conversationId, generated once per
 * browser session and reused for every chat message in it (see
 * ChatIdentityResolverInterface). This is deliberately NOT the PHP
 * session id itself — the session id is a sensitive primitive Magento
 * already protects; conversationId is a separate, purpose-built value
 * safe to hand back to the frontend/log, and only ever links to Magento's
 * own session cookie indirectly (whoever holds that cookie can read/
 * extend the same conversation, exactly like a cart or a logged-in
 * identity already work).
 */
class ChatSession extends \Magento\Framework\Session\SessionManager
{
    private const CONVERSATION_ID_KEY = 'conversation_id';

    public function getConversationId(): ?string
    {
        $value = $this->getData(self::CONVERSATION_ID_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setConversationId(string $conversationId): self
    {
        $this->setData(self::CONVERSATION_ID_KEY, $conversationId);

        return $this;
    }
}
