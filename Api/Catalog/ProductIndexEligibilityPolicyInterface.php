<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Determines whether a product snapshot may be indexed for a store scope.
 *
 * This is the first deterministic gate before any sanitization or embedding work.
 * It only answers scope and visibility questions; it never inspects content.
 */
interface ProductIndexEligibilityPolicyInterface
{
    public function evaluate(
        ProductSnapshotInterface $snapshot,
        ProductEligibilityContextInterface $context
    ): ProductEligibilityResultInterface;
}
