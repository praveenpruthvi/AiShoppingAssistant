<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

/**
 * Computes a readable text/background pairing for whichever half of an
 * Appearance color pair a merchant left unset — used by
 * ConfigurationReader::readAppearance() so an admin who only sets
 * `message_bubble_color` (or only `message_text_color`, or neither) always
 * gets a genuinely readable result instead of that one field falling back
 * to a fixed default that might clash with whatever else they did set.
 *
 * Uses the standard YIQ perceived-brightness formula — a simple, widely
 * used heuristic for "does this color read as light or dark," not a
 * claim of strict WCAG AA contrast-ratio compliance. Good enough for a
 * chat-bubble UI's own accent/text pairing; not a general-purpose
 * accessibility contrast checker.
 */
final class ColorContrast
{
    private const LIGHT_TEXT = '#ffffff';
    private const DARK_TEXT = '#1d1d1d';
    private const LIGHT_BACKGROUND = '#f2f2f2';
    private const DARK_BACKGROUND = '#2b2b2f';

    /**
     * @param string $hexColor A validated `#rgb`/`#rrggbb` hex color.
     */
    public function readableTextFor(string $hexColor): string
    {
        return $this->isLight($hexColor) ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /**
     * The inverse pairing: given only a text color, picks a background
     * that will read well behind it (light text needs a dark background
     * and vice versa) — used when a merchant sets `message_text_color`
     * but leaves `message_bubble_color` unset.
     *
     * @param string $hexColor A validated `#rgb`/`#rrggbb` hex color.
     */
    public function readableBackgroundFor(string $hexColor): string
    {
        return $this->isLight($hexColor) ? self::DARK_BACKGROUND : self::LIGHT_BACKGROUND;
    }

    private function isLight(string $hexColor): bool
    {
        [$red, $green, $blue] = $this->toRgb($hexColor);

        $luma = ($red * 299 + $green * 587 + $blue * 114) / 1000;

        return $luma >= 150;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function toRgb(string $hexColor): array
    {
        $hex = ltrim($hexColor, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
