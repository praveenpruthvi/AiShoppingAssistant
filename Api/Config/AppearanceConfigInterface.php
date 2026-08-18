<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

/**
 * Admin-configurable chat widget colors (Task 21, auto-contrast default
 * added Task 22). Every getter here always returns a concrete, usable
 * hex color — never null. A merchant who sets nothing gets this module's
 * original blue/gray defaults, unchanged; a merchant who sets only one
 * half of the message-bubble background/text pair gets the other half
 * automatically computed to stay readable against the half they did set
 * (see ColorContrast), rather than that half falling back to a fixed
 * default that might clash with it. Manual values, when both are set,
 * always win — this interface never overrides an explicit pair, however
 * it reads.
 */
interface AppearanceConfigInterface
{
    /**
     * The widget header/toggle-button background color.
     */
    public function primaryColor(): string;

    /**
     * The header/toggle-button text color — always auto-computed to read
     * well against primaryColor() (there is no separate admin field for
     * it, manual or otherwise), so a light custom primaryColor doesn't
     * silently pair with unreadable white text.
     */
    public function primaryTextColor(): string;

    /**
     * The assistant chat-bubble background color. Distinct from
     * primaryColor() — the header and the message bubbles are styled
     * independently.
     */
    public function messageBubbleColor(): string;

    /**
     * The assistant chat-bubble text color.
     */
    public function messageTextColor(): string;
}
