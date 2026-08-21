<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
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
 * The free-text `message` field is checked for a URL that doesn't match
 * any revalidated product's real url, and for a currency-like number
 * that doesn't match any revalidated product's real price. Both are
 * regex-based, not NLP: they catch the common phrasings ("$25", "25
 * dollars") but cannot catch every way a model could state a price or
 * URL, and the price check can still false-positive on a bare non-price
 * currency-shaped number that isn't qualified by a recognized threshold
 * word like "under"/"over" (see containsFabricatedPrice()). This is a
 * real improvement over no check at all, not a claim of complete
 * coverage.
 *
 * A percentage-off or "use code X" coupon claim (Task 34) is checked the
 * same way, against real, live-read promotion facts
 * (ProductPromotionInterface/CartPromotionInterface) rather than
 * anything the model itself asserts — see containsFabricatedDiscount().
 */
final class OutputValidator implements OutputValidatorInterface
{
    public const REASON_MALFORMED_RESPONSE = 'malformed_response';
    public const REASON_FABRICATED_SKU = 'fabricated_sku';
    public const REASON_FABRICATED_URL = 'fabricated_url';
    public const REASON_FABRICATED_PRICE = 'fabricated_price';
    public const REASON_FABRICATED_DISCOUNT = 'fabricated_discount';

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
     * Checked case-insensitively against the text immediately BEFORE each
     * mentioned price; see isPriceThresholdMention(). Broadened from the
     * original Task 22 list after live testing (Task 23) caught a real
     * gap: "all other jackets exceed $40" was rejected outright because
     * "exceed" wasn't recognized, even though "under $40"/"over $40" (the
     * same sentence's own inverse phrasing) already were.
     *
     * @var list<string>
     */
    private const BACKWARD_THRESHOLD_PHRASES = [
        'under', 'below', 'less than', 'cheaper than', 'up to', 'no more than',
        'maximum of', 'max of', 'within', 'budget of', 'over', 'above', 'more than',
        'at least', 'starting at', 'starting from', 'between', 'exceed', 'exceeds',
        'exceeding', 'in excess of', 'greater than', 'higher than', 'lower than',
        'beyond', 'priced at', 'priced under', 'priced over', 'priced below',
        'priced above', 'costs less than', 'costs more than', 'costing less than',
        'costing more than', 'for under', 'for less than', 'for over', 'as low as',
        'as high as', 'range of', 'ranging from', 'ceiling of', 'cap of',
    ];

    /**
     * Threshold phrases that trail the number instead of leading it ("$40
     * or less", "$40 max") — checked against the text immediately AFTER
     * each mentioned price. Added in Task 23: the original implementation
     * only ever looked backward, so "or less"/"or under"/"or below" being
     * present in the (backward-only) phrase list was dead code — none of
     * them can ever appear before the number they qualify.
     *
     * @var list<string>
     */
    private const FORWARD_THRESHOLD_PHRASES = [
        'or less', 'or under', 'or below', 'or fewer', 'or cheaper', 'or more',
        'or higher', 'or greater', 'max', 'maximum', 'cap', 'budget', 'or so',
        'and under', 'and below',
    ];

    /**
     * How far to look for a threshold phrase before/after a mentioned
     * price — enough for a phrase like "no more than about " (20 chars)
     * plus a little slack, not so much that it starts absorbing an
     * unrelated, genuine price claim elsewhere in the same sentence. The
     * window is additionally clipped to the previous/next mentioned
     * price's position (see extractMentionedPrices()) so one threshold
     * word can never "bleed" across two different numbers in the same
     * sentence — Task 23 found this happening live ("...is under $40,
     * with a price of $32" incorrectly exempted the genuine $32 product-
     * price claim, since "under" fell inside its naive 30-char backward
     * window despite actually qualifying the earlier $40).
     */
    private const THRESHOLD_CONTEXT_WINDOW = 30;

    public function __construct(
        private readonly LlmResponseParser $parser
    ) {
    }

    public function validate(
        ChatResponse $response,
        array $verifiedProducts,
        array $verifiedProductPromotions = [],
        array $verifiedCartPromotions = []
    ): OutputValidationResult {
        $parsed = $this->parser->parse($response->text);

        if ($parsed === null) {
            return OutputValidationResult::invalid(self::REASON_MALFORMED_RESPONSE);
        }

        if ($this->containsFabricatedUrl($parsed->message, $verifiedProducts)) {
            return OutputValidationResult::invalid(self::REASON_FABRICATED_URL);
        }

        if ($this->containsFabricatedPrice($parsed->message, $verifiedProducts)) {
            return OutputValidationResult::invalid(self::REASON_FABRICATED_PRICE);
        }

        if ($this->containsFabricatedDiscount($parsed->message, $verifiedProductPromotions, $verifiedCartPromotions)) {
            return OutputValidationResult::invalid(self::REASON_FABRICATED_DISCOUNT);
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

    /**
     * Scans free text for a URL that doesn't match any revalidated
     * product's real url. Added in Task 23 after live testing caught the
     * original blanket "any URL at all is fabricated" check rejecting a
     * genuine, 100%-accurate product URL the model had legitimately
     * retrieved (via get_product_details) and repeated back in a
     * "compare these two products" answer — the model was never wrong
     * here, the check itself couldn't tell a real URL from a made-up one.
     * Mirrors containsFabricatedPrice()'s exact "only reject a
     * non-matching mention" shape rather than "reject any mention at
     * all".
     *
     * Trailing sentence punctuation (a period ending the sentence, a
     * comma before "which", a closing parenthesis) is stripped before
     * comparing — prose naturally attaches these to a URL with no space,
     * which `\S+` greedily captures as part of the "URL".
     *
     * @param list<RevalidatedProduct> $verifiedProducts
     */
    private function containsFabricatedUrl(string $message, array $verifiedProducts): bool
    {
        if (preg_match_all('/https?:\/\/\S+/i', $message, $matches) === 0) {
            return false;
        }

        $realUrls = array_map(static fn (RevalidatedProduct $product): string => $product->url, $verifiedProducts);

        foreach ($matches[0] as $mentionedUrl) {
            $trimmed = rtrim($mentionedUrl, '.,;:!?)"\'');

            if (!in_array($trimmed, $realUrls, true)) {
                return true;
            }
        }

        return false;
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
        $candidates = [
            ...$this->findCandidates('/\$\s?(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)/', $message),
            ...$this->findCandidates('/(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)\s?(?:dollars|USD)\b/i', $message),
        ];

        usort($candidates, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $prices = [];
        $messageLength = strlen($message);

        foreach ($candidates as $index => $candidate) {
            $previousEnd = $index > 0 ? $candidates[$index - 1]['end'] : 0;
            $nextStart = $index < count($candidates) - 1 ? $candidates[$index + 1]['start'] : $messageLength;

            if ($this->isPriceThresholdMention($message, $candidate['start'], $candidate['end'], $previousEnd, $nextStart)) {
                continue;
            }

            $prices[] = $candidate['value'];
        }

        return $prices;
    }

    /**
     * @return list<array{start: int, end: int, value: float}>
     */
    private function findCandidates(string $pattern, string $message): array
    {
        if (preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $candidates = [];
        foreach ($matches[1] as $index => [$numberText, ]) {
            [$fullMatch, $start] = $matches[0][$index];

            $candidates[] = [
                'start' => $start,
                'end' => $start + strlen($fullMatch),
                'value' => (float)str_replace(',', '', $numberText),
            ];
        }

        return $candidates;
    }

    /**
     * True when a currency-shaped number is qualified by a nearby
     * threshold word — either leading it ("under $40") or trailing it
     * ("$40 or less") — a restated search constraint or a shipping/budget
     * cutoff, not a claim about a specific product's actual price, so
     * it's exempt from the real-price match check entirely.
     *
     * Both the backward and forward context windows are clipped to the
     * previous/next mentioned price's position, not just a fixed
     * character count — otherwise a threshold word qualifying one number
     * can "bleed" into an adjacent, genuine product-price claim later in
     * the same sentence (e.g. "...is under $40, with a price of $32" —
     * without clipping, "under" falls inside $32's naive backward window
     * too, incorrectly exempting a real price claim from ever being
     * checked).
     *
     * This deliberately still means a fabricated price phrased as a
     * threshold ("this one runs about $200" for a real $50 item) slips
     * through uncaught — a known, accepted trade-off: without it, *every*
     * price-constrained search ("jackets under $40") fails outright the
     * moment the model's own reply echoes the customer's stated budget
     * back, which is the far more common and more harmful failure mode in
     * practice.
     */
    private function isPriceThresholdMention(
        string $message,
        int $matchStart,
        int $matchEnd,
        int $previousMatchEnd,
        int $nextMatchStart
    ): bool {
        $backwardStart = max(0, $matchStart - self::THRESHOLD_CONTEXT_WINDOW, $previousMatchEnd);
        $backwardContext = mb_strtolower(substr($message, $backwardStart, $matchStart - $backwardStart));

        foreach (self::BACKWARD_THRESHOLD_PHRASES as $phrase) {
            if (str_contains($backwardContext, $phrase)) {
                return true;
            }
        }

        $forwardEnd = min(strlen($message), $matchEnd + self::THRESHOLD_CONTEXT_WINDOW, $nextMatchStart);
        $forwardContext = mb_strtolower(substr($message, $matchEnd, $forwardEnd - $matchEnd));

        foreach (self::FORWARD_THRESHOLD_PHRASES as $phrase) {
            if (str_contains($forwardContext, $phrase)) {
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

    /**
     * Mirrors containsFabricatedPrice()'s exact shape (Task 34): scan free
     * text for a percentage-off or coupon-code claim, reject the response
     * if any mentioned value doesn't match a real, live-read promotion.
     * Regex-based, not NLP — the same documented, accepted limitation as
     * the price/URL checks (see this class's own top-level docblock).
     *
     * @param list<ProductPromotionInterface> $verifiedProductPromotions
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    private function containsFabricatedDiscount(
        string $message,
        array $verifiedProductPromotions,
        array $verifiedCartPromotions
    ): bool {
        if ($this->containsFabricatedPercentage($message, $verifiedProductPromotions, $verifiedCartPromotions)) {
            return true;
        }

        return $this->containsFabricatedCouponCode($message, $verifiedCartPromotions);
    }

    /**
     * @param list<ProductPromotionInterface> $verifiedProductPromotions
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    private function containsFabricatedPercentage(
        string $message,
        array $verifiedProductPromotions,
        array $verifiedCartPromotions
    ): bool {
        if (preg_match_all('/(\d{1,3})\s?%/', $message, $matches) === 0) {
            return false;
        }

        $realPercents = [];
        foreach ($verifiedProductPromotions as $promotion) {
            $realPercents[] = (int) round($promotion->percentOff());
        }
        foreach ($verifiedCartPromotions as $promotion) {
            if (preg_match('/(\d{1,3})\s?%/', $promotion->discountDescription(), $descriptionMatch) === 1) {
                $realPercents[] = (int) $descriptionMatch[1];
            }
        }

        foreach ($matches[1] as $mentionedPercent) {
            if (!in_array((int) $mentionedPercent, $realPercents, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only checks text immediately following the word "code" (e.g. "use
     * code SUMMER10", "coupon code: SAVE15") — deliberately narrow,
     * mirroring containsFabricatedPrice()'s own "regex-based, not NLP"
     * scope rather than treating any capitalized word as a claimed code.
     *
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    private function containsFabricatedCouponCode(string $message, array $verifiedCartPromotions): bool
    {
        if (preg_match_all('/\bcode[:\s]+([A-Za-z0-9]{3,20})\b/i', $message, $matches) === 0) {
            return false;
        }

        $realCodes = [];
        foreach ($verifiedCartPromotions as $promotion) {
            if ($promotion->couponCode() !== null) {
                $realCodes[] = mb_strtoupper($promotion->couponCode());
            }
        }

        foreach ($matches[1] as $mentionedCode) {
            if (!in_array(mb_strtoupper($mentionedCode), $realCodes, true)) {
                return true;
            }
        }

        return false;
    }
}
