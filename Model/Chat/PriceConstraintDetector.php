<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

/**
 * Regex-based detection of an explicit price threshold in a customer's own
 * query text ("jackets below $60", "something over $20", "up to $60",
 * "within $50", "between $20 and $60", "something around $50") —
 * deliberately simple, not a full NLU layer, per this task's own
 * instruction, and modeled directly on OutputValidator::
 * extractMentionedPrices()'s existing currency-shaped-number regex
 * (duplicated rather than shared: that method solves a different problem
 * — spotting a fabricated price in the *response* text — and coupling
 * the two would tie together two independently-evolving concerns for no
 * real benefit).
 *
 * Distinguishes an exclusive bound ("under", "below", "less than",
 * "cheaper than", "over", "above", "more than" — strictly less/greater
 * than) from an inclusive one ("up to", "no more than", "within $60", "at
 * least", "$60 or less"/"$60 or under" — less/greater than *or equal to*),
 * since a real product priced at exactly the stated threshold is a
 * genuine edge case a customer would reasonably expect handled correctly
 * either way. "within $X" was a real, confirmed gap (Task 27) — present
 * in OutputValidator's own, separately-maintained threshold-phrase list
 * since Task 22 but never carried over here when this detector was built
 * in Task 25 — live-reproduced "show me price within $50" detecting no
 * constraint at all.
 *
 * "around $X" is treated as a *range*, not a single inclusive bound
 * (Task 27) — deliberately not folded into INCLUSIVE_MAX_PHRASES.
 * "around"/"about" mean "somewhere near this figure," not "up to this
 * figure": a customer asking for something around $50 would still
 * reasonably expect a genuinely close $55 item to show up, which a max-
 * bound-only interpretation would incorrectly exclude. Modeled as a
 * symmetric ±20% band (e.g. "around $50" -> $40-$60 inclusive) — a
 * simple, easily-explained figure, not a precisely UX-tested one.
 * Deliberately "around" only, not also "about": "about" collides with
 * its far more common non-price sense ("tell me about $50 gift cards"
 * means the $50 gift card product line specifically, not "somewhere near
 * $50"), and misreading that as a fuzzy range would be a real regression,
 * not a coverage improvement.
 *
 * Only the first max-shaped and first min-shaped mention in a message are
 * used — real customer queries essentially never state more than one of
 * each, and the "between $X and $Y"/"around $X" patterns are checked
 * first and handle their own two-number/single-number cases directly.
 */
final class PriceConstraintDetector
{
    private const EXCLUSIVE_MAX_PHRASES = ['under', 'below', 'less than', 'cheaper than'];

    private const INCLUSIVE_MAX_PHRASES = [
        'up to', 'no more than', 'maximum of', 'max of', 'budget of', 'cap of', 'within',
        'or less', 'or under', 'or below', 'or cheaper', 'max', 'maximum', 'cap', 'budget',
    ];

    private const EXCLUSIVE_MIN_PHRASES = ['over', 'above', 'more than'];

    private const INCLUSIVE_MIN_PHRASES = [
        'at least', 'starting at', 'starting from', 'or more', 'or higher', 'or greater',
    ];

    /**
     * The symmetric band "around $X" expands to — see the class docblock
     * for why this is a range rather than a single inclusive bound.
     */
    private const AROUND_TOLERANCE = 0.20;

    /**
     * How far to look for a threshold phrase before/after a mentioned
     * price — enough for a short phrase plus a little slack. Customer
     * queries are short and rarely mention more than one price, so this
     * doesn't need OutputValidator's more elaborate multi-mention
     * bleed-prevention (clipping to a neighboring match's position).
     */
    private const CONTEXT_WINDOW = 20;

    public function detect(string $message): ?PriceConstraint
    {
        if (preg_match(
            '/between\s*\$?\s*(\d+(?:\.\d{1,2})?)\s*(?:and|-|to)\s*\$?\s*(\d+(?:\.\d{1,2})?)/i',
            $message,
            $rangeMatch
        ) === 1) {
            $first = (float) $rangeMatch[1];
            $second = (float) $rangeMatch[2];

            return new PriceConstraint(max($first, $second), true, min($first, $second), true);
        }

        $candidates = $this->findCandidates($message);
        if ($candidates === []) {
            return null;
        }

        $max = null;
        $maxInclusive = true;
        $min = null;
        $minInclusive = true;

        foreach ($candidates as $candidate) {
            $backward = mb_strtolower(substr(
                $message,
                max(0, $candidate['start'] - self::CONTEXT_WINDOW),
                min(self::CONTEXT_WINDOW, $candidate['start'])
            ));
            $forward = mb_strtolower(substr($message, $candidate['end'], self::CONTEXT_WINDOW));

            if ($max === null && $min === null && $this->matchesAny($backward, ['around'])) {
                $max = $candidate['value'] * (1 + self::AROUND_TOLERANCE);
                $min = $candidate['value'] * (1 - self::AROUND_TOLERANCE);
                $maxInclusive = true;
                $minInclusive = true;
                continue;
            }

            if ($max === null && $this->matchesAny($backward, self::EXCLUSIVE_MAX_PHRASES)) {
                $max = $candidate['value'];
                $maxInclusive = false;
                continue;
            }

            if ($max === null
                && ($this->matchesAny($backward, self::INCLUSIVE_MAX_PHRASES)
                    || $this->matchesAny($forward, self::INCLUSIVE_MAX_PHRASES))
            ) {
                $max = $candidate['value'];
                $maxInclusive = true;
                continue;
            }

            if ($min === null && $this->matchesAny($backward, self::EXCLUSIVE_MIN_PHRASES)) {
                $min = $candidate['value'];
                $minInclusive = false;
                continue;
            }

            if ($min === null
                && ($this->matchesAny($backward, self::INCLUSIVE_MIN_PHRASES)
                    || $this->matchesAny($forward, self::INCLUSIVE_MIN_PHRASES))
            ) {
                $min = $candidate['value'];
                $minInclusive = true;
            }
        }

        if ($max === null && $min === null) {
            return null;
        }

        return new PriceConstraint($max, $maxInclusive, $min, $minInclusive);
    }

    /**
     * @return list<array{start: int, end: int, value: float}>
     */
    private function findCandidates(string $message): array
    {
        $candidates = [];

        foreach ([
            '/\$\s?(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)/',
            '/(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)\s?(?:dollars|USD)\b/i',
        ] as $pattern) {
            if (preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[1] as $index => [$numberText, ]) {
                [$fullMatch, $start] = $matches[0][$index];

                $candidates[] = [
                    'start' => $start,
                    'end' => $start + strlen($fullMatch),
                    'value' => (float) str_replace(',', '', $numberText),
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $candidates;
    }

    /**
     * @param list<string> $phrases
     */
    private function matchesAny(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
