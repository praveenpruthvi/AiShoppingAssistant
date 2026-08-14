<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Strips unsafe markup and excessive content from untrusted catalogue text.
 *
 * Catalogue descriptions, category names, and attribute values are treated as
 * data, never as instructions. The sanitizer removes scripts, styles, event
 * handlers, hidden content, comments, and over-long inputs, and returns plain,
 * collapsed, length-capped text.
 */
interface UntrustedContentSanitizerInterface
{
    /** Raw input above this many characters is truncated before parsing. */
    public const MAX_RAW_INPUT_CHARACTERS = 100000;

    /** Maximum sanitized product name length. */
    public const MAX_PRODUCT_NAME_CHARACTERS = 512;

    /** Maximum sanitized SKU length. */
    public const MAX_SKU_CHARACTERS = 128;

    /** Maximum sanitized category name or path length. */
    public const MAX_CATEGORY_CHARACTERS = 1024;

    /** Maximum sanitized attribute label length. */
    public const MAX_ATTRIBUTE_LABEL_CHARACTERS = 256;

    /** Maximum sanitized single attribute value length. */
    public const MAX_ATTRIBUTE_VALUE_CHARACTERS = 2048;

    /** Maximum sanitized short description length. */
    public const MAX_SHORT_DESCRIPTION_CHARACTERS = 8000;

    /** Maximum sanitized long description length. */
    public const MAX_LONG_DESCRIPTION_CHARACTERS = 16000;

    /** Maximum searchable text length assembled from all fields. */
    public const MAX_SEARCHABLE_TEXT_CHARACTERS = 32000;

    /**
     * Returns a plain-text, length-capped, whitespace-collapsed copy of the input.
     * Returns an empty string for null, empty, or entirely stripped input.
     */
    public function sanitize(?string $text): string;
}
