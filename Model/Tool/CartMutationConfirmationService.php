<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Magento\Framework\App\CacheInterface;

/**
 * Tracks proposed cart mutations awaiting confirmation, backed by Magento's
 * generic application cache — the same pattern CacheCircuitBreaker (Task 5)
 * already established for short-lived, TTL-bound cross-call state, chosen
 * for the identical reason: this is a simple token-with-TTL, and a new
 * database table would be disproportionate.
 *
 * This is the server-side enforcement behind add_to_cart/remove_from_cart's
 * confirmation gate. The model is NEVER trusted to assert that a customer
 * confirmed something — it can only "confirm" by echoing back an opaque,
 * unguessable token this service generated and handed it in a prior tool
 * result, and only when that token's stored proposal exactly matches the
 * new call's arguments. A token is single-use (consumed on first redemption
 * attempt, matched or not) and is refused if redemption is attempted in the
 * same turn that created it (see ToolContext::$turnId) — a genuine
 * confirmation must come from a later, separate converse() invocation, not
 * from the model simply calling the same tool twice within one automated
 * round-trip.
 */
final class CartMutationConfirmationService
{
    private const CACHE_ID_PREFIX = 'aavirbhava_ai_cart_confirmation_';
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @param array<string, mixed> $proposal the exact identifying fields of
     *     the proposed change (action, cart id, sku, qty, ...) — compared
     *     verbatim on redemption
     */
    public function createToken(string $turnId, array $proposal): string
    {
        $token = bin2hex(random_bytes(16));

        $payload = json_encode(['turnId' => $turnId, 'proposal' => $proposal], JSON_THROW_ON_ERROR);
        $this->cache->save($payload, $this->cacheId($token), [], self::TTL_SECONDS);

        return $token;
    }

    /**
     * Consumes $token (regardless of outcome, so it can never be replayed)
     * and returns true only when it existed, had not expired, was created
     * in a different turn than this redemption attempt, and its stored
     * proposal exactly matches $proposal.
     *
     * @param array<string, mixed> $proposal
     */
    public function redeem(string $token, string $turnId, array $proposal): bool
    {
        $cacheId = $this->cacheId($token);
        $raw = $this->cache->load($cacheId);
        $this->cache->remove($cacheId);

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($decoded) || !isset($decoded['turnId'], $decoded['proposal']) || !is_array($decoded['proposal'])) {
            return false;
        }

        if ($decoded['turnId'] === $turnId) {
            return false;
        }

        return $decoded['proposal'] === $proposal;
    }

    private function cacheId(string $token): string
    {
        return self::CACHE_ID_PREFIX . hash('sha256', $token);
    }
}
