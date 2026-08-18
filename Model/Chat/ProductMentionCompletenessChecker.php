<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Catches the specific "here are 2 jackets" / one card gap live-testing
 * surfaced (Task 23): the model names more real, verified products in its
 * own free-text message than it actually selected into `product_skus`,
 * even after ResponseContractFormatter (Task 18) already instructs it to
 * include every product it names. OutputValidator's own fabricated_sku
 * check can't catch this — it only ever rejects a SKU the model *did*
 * select that isn't real; it has no way to notice a real, verified
 * product the model simply forgot to select at all.
 *
 * Deliberately mechanical, not fuzzy NLP: a product only counts as
 * "mentioned but missing" when its real, exact product name appears as a
 * literal (case-insensitive) substring of the message. This under-reports
 * — a paraphrase ("the Jade jacket" instead of "Jade Yoga Jacket") is
 * missed entirely — but never over-reports a product the model didn't
 * actually name, which would otherwise risk ChatEntryPipeline retrying
 * pointlessly on a false alarm.
 */
final class ProductMentionCompletenessChecker
{
    /**
     * @param list<string> $selectedSkus SKUs already present in
     *     product_skus — never flagged even if their name also appears in
     *     the message text.
     * @param list<RevalidatedProduct> $candidateProducts every product the
     *     model actually had in view this turn (retrieval context plus
     *     whatever tool calls verified) — the only pool a "missing"
     *     product can come from, so this can never flag a product the
     *     model was never shown.
     *
     * @return list<RevalidatedProduct>
     */
    public function findMissingProducts(string $message, array $selectedSkus, array $candidateProducts): array
    {
        $selected = array_flip($selectedSkus);
        $missing = [];

        foreach ($candidateProducts as $product) {
            if (isset($selected[$product->sku])) {
                continue;
            }

            if ($this->nameAppearsIn($product->name, $message)) {
                $missing[] = $product;
            }
        }

        return $missing;
    }

    private function nameAppearsIn(string $name, string $message): bool
    {
        $name = trim($name);

        return $name !== '' && mb_stripos($message, $name) !== false;
    }
}
