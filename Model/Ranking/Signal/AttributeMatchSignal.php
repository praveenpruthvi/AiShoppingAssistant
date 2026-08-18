<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 1 signal: query-vs-candidate attribute overlap.
 *
 * Deliberately simple, since query parsing/intent extraction is out of
 * scope for this pipeline: tokenizes the raw query text and boosts
 * candidates whose category names or attribute values (e.g. brand, color,
 * material — whatever the store's searchable_attribute_codes configure)
 * share tokens with the query. Not a substitute for real filter/facet
 * matching once query parsing exists.
 */
final class AttributeMatchSignal implements RankingSignalInterface
{
    private const BOOST_PER_MATCH = 0.15;
    private const MAX_BOOST = 0.5;
    private const MIN_TOKEN_LENGTH = 3;

    public function apply(SearchContext $context, array $candidates): array
    {
        $queryTokens = $this->tokenize($context->queryText);

        if ($queryTokens === []) {
            return $candidates;
        }

        return array_map(
            function (SearchCandidate $candidate) use ($queryTokens): SearchCandidate {
                $candidateTokens = $this->candidateTokens($candidate);
                $overlap = count(array_intersect($queryTokens, $candidateTokens));
                $boost = min($overlap * self::BOOST_PER_MATCH, self::MAX_BOOST);

                return $candidate->withScore($candidate->score + $boost);
            },
            $candidates
        );
    }

    /**
     * @return list<string>
     */
    private function candidateTokens(SearchCandidate $candidate): array
    {
        $tokens = [];

        foreach ($candidate->categoryNames as $categoryName) {
            $tokens = array_merge($tokens, $this->tokenize($categoryName));
        }

        foreach ($candidate->attributes as $attribute) {
            foreach ($attribute['values'] as $value) {
                $tokens = array_merge($tokens, $this->tokenize($value));
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= self::MIN_TOKEN_LENGTH
        ));
    }
}
