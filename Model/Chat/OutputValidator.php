<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseParser;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\OutputValidationResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Validates a raw provider ChatResponse against the already-live-revalidated
 * candidate set and shapes it into the structured response contract.
 *
 * Deliberately conservative: a response that fabricates even one SKU is
 * rejected in full (not filtered down to the SKUs that did check out),
 * matching the fail-closed philosophy used throughout this module (e.g.
 * ProductIndexEligibilityPolicy, alias-ownership proofs). A response that
 * hallucinated once has already demonstrated it can't be trusted for the
 * rest of its content.
 *
 * The free-text `message` field is checked for a leaked URL and for a
 * currency-like number that doesn't match any revalidated product's real
 * price. Both are regex-based, not NLP: they catch the common phrasings
 * ("$25", "25 dollars") but cannot catch every way a model could state a
 * price, and the price check can still false-positive on a bare
 * non-price currency-shaped number that isn't qualified by a recognized
 * threshold word like "under"/"over" (see containsFabricatedPrice()).
 * This is a real improvement over no check at all, not a claim of
 * complete coverage.
 */
final class OutputValidator implements OutputValidatorInterface
{
    public const REASON_MALFORMED_RESPONSE = 'malformed_response';
    public const REASON_FABRICATED_SKU = 'fabricated_sku';
    public const REASON_FABRICATED_URL = 'fabricated_url';
    public const REASON_FABRICATED_PRICE = 'fabricated_price';

    /**
     * Half a currency unit — covers casual "about $25" rounding to the
     * nearest whole dollar for a price like $24.99 or $25.49 (both round
     * to $25, at most $0.50 away) without being loose enough to let a
     * genuinely different price slip through.
     */
    private const PRICE_TOLERANCE = 0.50;

    /**
     * Phrases that mark a nearby currency-shaped number as a threshold or
     * constraint being restated (a search filter, a shipping cutoff, a
     * budget ceiling) rather than a claim about one specific product's
     * actual price. A model answering "jackets under $40" legitimately
     * echoes "$40" back in its own message — that number was never meant
     * to be checked against any product's real price, since it isn't one.
     * Checked case-insensitively against the text immediately before each
     * mentioned price; see isPriceThresholdMention().
     *
     * @var list<string>
     */
    private const THRESHOLD_PHRASES = [
        'under', 'below', 'less than', 'cheaper than', 'up to', 'no more than',
        'maximum of', 'max of', 'within', 'budget of', 'or less', 'or under',
        'or below', 'over', 'above', 'more than', 'at least', 'starting at', 'between',
    ];

    /**
     * How far back (characters) to look for a threshold phrase before a
     * mentioned price — enough for a phrase like "no more than about "
     * (20 chars) plus a little slack, not so much that it starts
     * absorbing an unrelated, genuine price claim earlier in the same
     * sentence.
     */
    private const THRESHOLD_CONTEXT_WINDOW = 30;

    public function __construct(
        private readonly LlmResponseParser $parser
    ) {
    }

    public function validate(ChatResponse $response, array $verifiedProducts): OutputValidationResult
    {
        $parsed = $this->parser->parse($response->text);

        if ($parsed === null) {
            return OutputValidationResult::invalid(self::REASON_MALFORMED_RESPONSE);
        }

        if ($this->containsUrl($parsed->message)) {
            return OutputValidationResult::invalid(self::REASON_FABRICATED_URL);
        }

        if ($this->containsFabricatedPrice($parsed->message, $verifiedProducts)) {
            return OutputValidationResult::invalid(self::REASON_FABRICATED_PRICE);
        }

        $verifiedBySku = [];
        foreach ($verifiedProducts as $product) {
            $verifiedBySku[$product->sku] = $product;
        }

        $products = [];
        foreach ($parsed->productSkus as $entry) {
            if (!isset($verifiedBySku[$entry['sku']])) {
                return OutputValidationResult::invalid(self::REASON_FABRICATED_SKU);
            }

            $products[] = new ProductResult($verifiedBySku[$entry['sku']], $entry['reason']);
        }

        $actions = [];
        foreach ($parsed->actions as $action) {
            foreach ($action['skus'] as $sku) {
                if (!isset($verifiedBySku[$sku])) {
                    return OutputValidationResult::invalid(self::REASON_FABRICATED_SKU);
                }
            }

            $actions[] = new AssistantAction($action['type'], $action['skus']);
        }

        $assistantResponse = new AssistantResponse(
            $parsed->message,
            $products,
            $parsed->followUpQuestions,
            $actions,
            new ResponseMetadata($response->provider, $response->model, $response->usedFallback)
        );

        return OutputValidationResult::valid($assistantResponse);
    }

    private function containsUrl(string $text): bool
    {
        return preg_match('/https?:\/\/\S+/i', $text) === 1;
    }

    /**
     * Scans free text for currency-like numbers ("$25", "$25.99", "25
     * dollars", "25 USD") and rejects the response if any of them, after
     * excluding threshold-phrased mentions (see THRESHOLD_PHRASES /
     * isPriceThresholdMention()), doesn't match a revalidated product's
     * real price/specialPrice within PRICE_TOLERANCE.
     *
     * A mentioned price only has to match *some* revalidated product, not
     * necessarily the one it's textually next to — a regex pass has no way
     * to attribute a number to a specific product. A price qualified by a
     * threshold word ("under $40", "free shipping over $50") is exempted
     * entirely rather than checked, since it's a restated constraint, not
     * a product-price claim — this was originally a documented false-
     * positive source but is now handled; a price mentioned without any
     * such qualifier (a bare discount amount like "$5 off", for instance)
     * can still false-positive exactly as before, since there's no
     * reliable way to tell that apart from a real price claim with a
     * regex pass alone.
     *
     * Only US-style `$`/`dollars`/`USD` phrasing is matched; this is not
     * store-currency-aware because OutputValidatorInterface::validate()
     * has no store scope to read a currency symbol/code from — doing that
     * properly would mean threading a store id through the interface,
     * which is a larger change than this task's regex-pass scope.
     *
     * @param list<RevalidatedProduct> $verifiedProducts
     */
    private function containsFabricatedPrice(string $message, array $verifiedProducts): bool
    {
        $mentionedPrices = $this->extractMentionedPrices($message);

        if ($mentionedPrices === []) {
            return false;
        }

        $realPrices = [];
        foreach ($verifiedProducts as $product) {
            $realPrices[] = $product->price;
            if ($product->specialPrice !== null) {
                $realPrices[] = $product->specialPrice;
            }
        }

        foreach ($mentionedPrices as $mentionedPrice) {
            if (!$this->matchesAnyRealPrice($mentionedPrice, $realPrices)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<float>
     */
    private function extractMentionedPrices(string $message): array
    {
        return [
            ...$this->extractPricesMatching('/\$\s?(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)/', $message),
            ...$this->extractPricesMatching('/(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)\s?(?:dollars|USD)\b/i', $message),
        ];
    }

    /**
     * @return list<float>
     */
    private function extractPricesMatching(string $pattern, string $message): array
    {
        if (preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $prices = [];
        foreach ($matches[1] as $index => [$numberText, ]) {
            $fullMatchOffset = $matches[0][$index][1];

            if ($this->isPriceThresholdMention($message, $fullMatchOffset)) {
                continue;
            }

            $prices[] = (float)str_replace(',', '', $numberText);
        }

        return $prices;
    }

    /**
     * True when a currency-shaped number at $matchOffset is qualified by a
     * nearby threshold word (see THRESHOLD_PHRASES) — a restated search
     * constraint or a shipping/budget cutoff, not a claim about a specific
     * product's actual price, so it's exempt from the real-price match
     * check entirely. This deliberately also means a fabricated price
     * phrased as a threshold ("this one runs about $200" for a real $50
     * item) would slip through uncaught — a known, accepted trade-off:
     * without it, *every* price-constrained search ("jackets under $40")
     * fails outright the moment the model's own reply echoes the
     * customer's stated budget back, which is the far more common and
     * more harmful failure mode in practice.
     */
    private function isPriceThresholdMention(string $message, int $matchOffset): bool
    {
        $contextStart = max(0, $matchOffset - self::THRESHOLD_CONTEXT_WINDOW);
        $context = mb_strtolower(substr($message, $contextStart, $matchOffset - $contextStart));

        foreach (self::THRESHOLD_PHRASES as $phrase) {
            if (str_contains($context, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<float> $realPrices
     */
    private function matchesAnyRealPrice(float $mentionedPrice, array $realPrices): bool
    {
        foreach ($realPrices as $realPrice) {
            if (abs($mentionedPrice - $realPrice) <= self::PRICE_TOLERANCE) {
                return true;
            }
        }

        return false;
    }
}
