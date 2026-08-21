<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;

/**
 * The single shared read/write point for per-LLM-provider token pricing,
 * used to compute real spend against the cost cap (Task 35). Replaces Task
 * 35's static, two-provider system.xml field-pairs with a dynamic,
 * provider-keyed table so a newly-registered LLM provider never requires a
 * new config field — see CLAUDE.md's "Per-provider cost config" section for
 * the binding design constraints.
 *
 * Global/catalog-wide, not store-scoped (mirrors MerchandisingBoost's and
 * AttributeIndexingSelectionRepositoryInterface's own deliberate no-store_id
 * precedent) — a provider's real-world API price is the same regardless of
 * which store view a request happens to be scoped to.
 */
interface ProviderCostRepositoryInterface
{
    /**
     * Every provider identifier this repository has an explicit row for,
     * and its configured price. An identifier with no row at all is
     * neither present here nor implicitly priced — callers needing a price
     * for an absent identifier should treat it as 0.0, matching
     * ProviderCostConfigInterface's own fail-safe default.
     *
     * @return array<string, array{input: float, output: float}>
     */
    public function all(): array;

    /**
     * Upserts one provider's pricing. Both prices must be non-negative; the
     * provider identifier must be syntactically valid (see
     * ProviderIdentifiers::isValid()). This repository has no registry
     * dependency of its own — a caller that also needs to confirm the
     * identifier belongs to a REAL, currently-registered provider (e.g. the
     * admin Save controller) must check LlmProviderRegistryInterface::has()
     * itself before calling this.
     *
     * @throws ConfigurationException when the identifier is syntactically
     *     invalid or either price is negative or unreasonably large
     */
    public function setPrice(
        string $providerIdentifier,
        float $pricePerThousandInputTokens,
        float $pricePerThousandOutputTokens
    ): void;
}
