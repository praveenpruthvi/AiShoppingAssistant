<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

/**
 * Small, shared text helpers for the keyword-only (LIKE-based) searchers
 * behind search_store_content — kept separate purely so escapeLike()'s
 * correctness-sensitive logic isn't duplicated between the CMS and
 * product searchers.
 */
final class ContentSearchTextUtility
{
    /**
     * Escapes LIKE metacharacters (`%`, `_`) in raw customer query text
     * before it is wrapped in `%...%` — otherwise a query that happens to
     * contain a literal `%` or `_` (e.g. "50% off") would be interpreted
     * as a wildcard instead of literal text. MySQL's default LIKE escape
     * character is backslash, so no ESCAPE clause is needed.
     */
    public function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Strips HTML and returns a short plain-text excerpt centered on the
     * first occurrence of $term, or the start of the text if $term isn't
     * found in it (e.g. a title-only match).
     */
    public function snippet(string $html, string $term, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))) ?? '');

        if ($text === '') {
            return '';
        }

        $position = $term !== '' ? stripos($text, $term) : false;
        $start = $position !== false ? max(0, $position - (int) ($length / 3)) : 0;
        $excerpt = substr($text, $start, $length);

        return ($start > 0 ? '…' : '') . trim($excerpt) . (strlen($text) > $start + $length ? '…' : '');
    }
}
