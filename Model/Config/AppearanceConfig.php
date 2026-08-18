<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\AppearanceConfigInterface;

final readonly class AppearanceConfig implements AppearanceConfigInterface
{
    public function __construct(
        private string $primaryColor,
        private string $primaryTextColor,
        private string $messageBubbleColor,
        private string $messageTextColor
    ) {
    }

    public function primaryColor(): string
    {
        return $this->primaryColor;
    }

    public function primaryTextColor(): string
    {
        return $this->primaryTextColor;
    }

    public function messageBubbleColor(): string
    {
        return $this->messageBubbleColor;
    }

    public function messageTextColor(): string
    {
        return $this->messageTextColor;
    }
}
