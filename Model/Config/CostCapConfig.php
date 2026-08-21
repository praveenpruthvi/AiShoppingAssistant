<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use InvalidArgumentException;

final readonly class CostCapConfig implements CostCapConfigInterface
{
    /**
     * @param list<string> $notificationEmails
     */
    public function __construct(
        private float $capAmount,
        private string $period,
        private int $warningThresholdPercent,
        private bool $allowOverride,
        private array $notificationEmails
    ) {
        if ($capAmount < 0.0) {
            throw new InvalidArgumentException('Cost cap amount must not be negative.');
        }

        if ($warningThresholdPercent < 1 || $warningThresholdPercent > 99) {
            throw new InvalidArgumentException('Warning threshold percent must be between 1 and 99.');
        }

        foreach ($notificationEmails as $email) {
            if (!is_string($email) || $email === '') {
                throw new InvalidArgumentException('Every notification email must be a non-empty string.');
            }
        }
    }

    public function capAmount(): float
    {
        return $this->capAmount;
    }

    public function period(): string
    {
        return $this->period;
    }

    public function warningThresholdPercent(): int
    {
        return $this->warningThresholdPercent;
    }

    public function allowOverride(): bool
    {
        return $this->allowOverride;
    }

    public function notificationEmails(): array
    {
        return $this->notificationEmails;
    }
}
