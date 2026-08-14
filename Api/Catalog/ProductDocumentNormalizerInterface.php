<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Turns a raw, untrusted catalogue snapshot into an eligible, sanitized document.
 *
 * Pipeline: eligibility gate, sanitization, attribute policy, empty-value
 * pruning, deterministic ordering, searchable-text assembly, and content hashes.
 * The result is deterministic and idempotent for a given snapshot.
 */
interface ProductDocumentNormalizerInterface
{
    public function normalize(
        ProductSnapshotInterface $snapshot,
        ProductEligibilityContextInterface $context
    ): ProductNormalizationResultInterface;
}
