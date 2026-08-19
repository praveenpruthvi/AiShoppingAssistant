<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Deterministically corrects AssistantResponse::$products against a
 * detected PriceConstraint, once the model's response has already passed
 * OutputValidator — this is the fix for a real, live-reproduced bug: the
 * LLM can correctly retrieve every matching candidate (the debug log's
 * `availability_filter` before/after counts prove this) yet still under-
 * select which ones it puts in product_skus, silently dropping real
 * matches with nothing telling the customer they were dropped.
 *
 * Deliberately code-only, not another model round-trip (unlike
 * ProductMentionCompletenessChecker's retry from Task 23): the correct
 * answer is fully computable from data this class already has (real,
 * live-revalidated prices, a regex-parsed constraint), so asking the
 * model again would only add latency and a second chance to get it wrong,
 * for no benefit over just computing the right answer directly. Every
 * added product's `reason` is a plain, honest, code-generated statement
 * of the actual price and the actual constraint it satisfies — never a
 * claim invented on the model's behalf.
 *
 * Scope: corrects `products[]` only. A product added this way may not be
 * named anywhere in the response's own `message` text — accepted as a
 * lesser, disclosed trade-off; a product card with no matching narrative
 * mention is a smaller problem than a real match silently missing
 * altogether, which is what this class exists to prevent.
 */
final class PriceConstraintReconciler
{
    /**
     * @param list<RevalidatedProduct> $verifiedProducts every product this
     *     turn's response was allowed to draw from — the same set
     *     OutputValidator already validated $response's SKUs against
     */
    public function reconcile(
        ?PriceConstraint $constraint,
        AssistantResponse $response,
        array $verifiedProducts
    ): PriceConstraintReconciliationResult {
        if ($constraint === null) {
            return new PriceConstraintReconciliationResult($response, [], []);
        }

        $verifiedBySku = [];
        foreach ($verifiedProducts as $product) {
            $verifiedBySku[$product->sku] = $product;
        }

        $qualifyingBySku = [];
        foreach ($verifiedBySku as $sku => $product) {
            if ($constraint->isSatisfiedBy($this->effectivePrice($product))) {
                $qualifyingBySku[$sku] = $product;
            }
        }

        $alreadySelectedSkus = array_map(
            static fn (ProductResult $productResult): string => $productResult->product->sku,
            $response->products
        );

        $removedSkus = [];
        $keptProducts = [];
        foreach ($response->products as $productResult) {
            $verified = $verifiedBySku[$productResult->product->sku] ?? $productResult->product;

            if ($constraint->isSatisfiedBy($this->effectivePrice($verified))) {
                $keptProducts[] = $productResult;
            } else {
                $removedSkus[] = $productResult->product->sku;
            }
        }

        $addedSkus = [];
        foreach ($qualifyingBySku as $sku => $verified) {
            if (in_array($sku, $alreadySelectedSkus, true)) {
                continue;
            }

            $addedSkus[] = $sku;
            $keptProducts[] = new ProductResult($verified, $this->generatedReason($verified));
        }

        if ($addedSkus === [] && $removedSkus === []) {
            return new PriceConstraintReconciliationResult($response, [], []);
        }

        $correctedResponse = new AssistantResponse(
            $response->message,
            $keptProducts,
            $response->followUpQuestions,
            $this->pruneActions($response->actions, $removedSkus),
            $response->metadata
        );

        return new PriceConstraintReconciliationResult($correctedResponse, $addedSkus, $removedSkus);
    }

    private function effectivePrice(RevalidatedProduct $product): float
    {
        return $product->specialPrice ?? $product->price;
    }

    private function generatedReason(RevalidatedProduct $product): string
    {
        return sprintf(
            'Priced at $%s, matching your requested price range.',
            number_format($this->effectivePrice($product), 2)
        );
    }

    /**
     * @param list<AssistantAction> $actions
     * @param list<string> $removedSkus
     *
     * @return list<AssistantAction>
     */
    private function pruneActions(array $actions, array $removedSkus): array
    {
        if ($removedSkus === []) {
            return $actions;
        }

        $pruned = [];
        foreach ($actions as $action) {
            $remainingSkus = array_values(array_diff($action->skus, $removedSkus));

            if ($remainingSkus === []) {
                continue;
            }

            $pruned[] = new AssistantAction($action->type, $remainingSkus);
        }

        return $pruned;
    }
}
