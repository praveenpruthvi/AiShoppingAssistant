<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use InvalidArgumentException;

/**
 * The identity a real storefront request resolves for one chat message:
 * a stable per-session conversation id, the real customer/guest group,
 * and — only when cart tools could possibly be offered — a real masked
 * quote id. Everything here is derived server-side from Magento's own
 * session/cart mechanisms (see ChatIdentityResolverInterface); none of it
 * is ever accepted directly from the client request.
 */
final readonly class ChatRequestIdentity
{
    public function __construct(
        public string $conversationId,
        public int $customerGroupId,
        public ?string $cartId
    ) {
        if ($conversationId === '') {
            throw new InvalidArgumentException('A chat request identity requires a non-empty conversation id.');
        }

        if ($customerGroupId < 0) {
            throw new InvalidArgumentException('A chat request identity requires a non-negative customer group id.');
        }
    }
}
