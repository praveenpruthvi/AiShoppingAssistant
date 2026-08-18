<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use InvalidArgumentException;

/**
 * Store, customer-group, and cart scope for one tool call, plus a
 * same-turn identifier used to gate cart-mutation confirmation.
 *
 * customerGroupId is nullable for the same reason it is everywhere else in
 * this module: no Controller/session layer resolves a real logged-in
 * customer's group yet, so it always arrives null in practice today and
 * downstream services (LiveRevalidationService) resolve that to Magento's
 * NOT_LOGGED_IN group. cartId is nullable for the identical reason — no
 * Controller/session layer resolves a real masked quote id yet either, so
 * every cart tool must treat a null cartId as an honest "no cart available"
 * outcome (see CartResolverInterface).
 *
 * turnId is NOT supplied by callers in practice — it defaults to a fresh
 * random value generated once per ToolContext instance. Since
 * ToolCallingChatService constructs exactly one ToolContext per converse()
 * call and reuses it across every round/tool-call within that call, turnId
 * is stable within one customer turn and different across separate turns.
 * CartMutationConfirmationService uses this to refuse redeeming a
 * confirmation token in the same turn that created it — a real confirmation
 * must come from a later, separate converse() invocation, not from the
 * model simply calling a mutating tool twice in the same automated
 * round-trip.
 */
final readonly class ToolContext
{
    public string $turnId;

    public function __construct(
        public int $storeId,
        public ?int $customerGroupId,
        public ?string $cartId = null,
        ?string $turnId = null
    ) {
        if ($storeId < 1) {
            throw new InvalidArgumentException('A tool context requires a positive store id.');
        }

        $this->turnId = $turnId ?? bin2hex(random_bytes(8));
    }
}
