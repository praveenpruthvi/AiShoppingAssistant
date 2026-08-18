<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Exception\ChatInputException;
use Magento\Framework\Phrase;

/**
 * Thin, fast-failing sanity check on a raw customer message.
 *
 * Deliberately does not overlap with CommerceScopeClassifier: this only
 * checks that the message is well-formed input (non-empty after trim,
 * valid UTF-8, within the configured length ceiling), never whether it is
 * commerce-relevant.
 */
final class ChatInputValidator
{
    public function validate(string $message, GuardrailConfigInterface $guardrails): string
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            throw new ChatInputException(
                new Phrase('Your message is empty.')
            );
        }

        if (preg_match('//u', $trimmed) !== 1) {
            throw new ChatInputException(
                new Phrase('Your message contains invalid characters.')
            );
        }

        if (mb_strlen($trimmed, 'UTF-8') > $guardrails->maxInputCharacters()) {
            throw new ChatInputException(
                new Phrase('Your message is too long.')
            );
        }

        return $trimmed;
    }
}
