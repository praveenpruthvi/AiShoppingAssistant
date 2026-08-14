<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\UntrustedContentSanitizerInterface;

/**
 * Strips unsafe markup and excessive content from untrusted catalogue text.
 *
 * Catalogue descriptions, category names, and attribute values are treated as
 * data, never as instructions. The sanitizer uses two paths: a DOM-based pass
 * for text that clearly looks like HTML, and a plain entity-decode pass for
 * everything else. Both paths decode entities, remove control characters,
 * collapse whitespace, and never resolve external entities or network targets.
 */
final class UntrustedContentSanitizer implements UntrustedContentSanitizerInterface
{
    private const BLOCKED_TAGS = [
        'script', 'style', 'noscript', 'template',
        'iframe', 'object', 'embed', 'form',
    ];

    private const KNOWN_HTML_TAG_PATTERN =
        '~</?(?:!doctype|html|head|body|div|p|span|a|img|br|hr|table|thead|tbody|tr|td|th|'
        . 'ul|ol|li|dl|dt|dd|h[1-6]|strong|em|b|i|u|s|small|blockquote|pre|code|section|'
        . 'article|aside|header|footer|nav|main|form|input|button|select|option|textarea|'
        . 'iframe|object|embed|script|style|noscript|template|video|audio|figure|figcaption|'
        . 'label|meta|link|title|hgroup|mark|time)\b[^>]*>~i';

    public function sanitize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_substr($text, 0, self::MAX_RAW_INPUT_CHARACTERS);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        if (preg_match(self::KNOWN_HTML_TAG_PATTERN, $text) === 1) {
            $text = $this->stripMarkup($text);
        } else {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = $this->stripBlockedElements($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function stripMarkup(string $text): string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $text,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
            );

            if ($loaded === false) {
                return $this->regexStrip($text);
            }

            $xpath = new \DOMXPath($document);

            $this->removeNodes($xpath->query('//processing-instruction()'));
            $this->removeNodes($xpath->query('//comment()'));
            $this->removeNodes($xpath->query('//*[self::script or self::style or self::noscript'
                . ' or self::template or self::iframe or self::object or self::embed or self::form]'));
            $this->removeNodes($xpath->query(
                '//*[@hidden or @aria-hidden="true"'
                . ' or contains(@style,"display:none") or contains(@style,"display: none")'
                . ' or contains(@style,"visibility:hidden") or contains(@style,"visibility: hidden")]'
            ));

            $body = $xpath->query('//body')->item(0);
            if ($body === null) {
                return $this->regexStrip($text);
            }

            $inner = '';
            foreach ($body->childNodes as $child) {
                $inner .= $document->saveHTML($child);
            }

            return $this->regexStrip($inner);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function regexStrip(string $text): string
    {
        $text = preg_replace('~<!--.*?-->~s', ' ', $text) ?? $text;
        $text = preg_replace('~<script\b.*?</script\s*>~is', ' ', $text) ?? $text;
        $text = preg_replace('~<style\b.*?</style\s*>~is', ' ', $text) ?? $text;
        $text = preg_replace('~<[^>]+>~', ' ', $text) ?? $text;

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Final defense against element content that survived parsing or that was
     * entity-encoded in a plain-text field. Removes blocked elements (with their
     * content) and comments, without decoding entities a second time.
     */
    private function stripBlockedElements(string $text): string
    {
        $text = preg_replace('~<!--.*?-->~s', ' ', $text) ?? $text;

        foreach (self::BLOCKED_TAGS as $tag) {
            $pattern = '~<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>~is';
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        $standalone = '~</?(?:' . implode('|', self::BLOCKED_TAGS) . ')\b[^>]*>~i';
        $text = preg_replace($standalone, ' ', $text) ?? $text;

        return $text;
    }

    /**
     * @param iterable<\DOMNode> $nodes
     */
    private function removeNodes(iterable $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }
}
