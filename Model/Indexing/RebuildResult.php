<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildMetricsInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Framework\Phrase;

final readonly class RebuildResult implements RebuildResultInterface
{
    /**
     * @var list<string>
     */
    private const OUTCOMES = [
        self::OUTCOME_ACTIVATED,
        self::OUTCOME_NO_OP,
        self::OUTCOME_ABORTED,
    ];

    public function __construct(
        private RebuildMetricsInterface $metrics,
        private string $outcome
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new ProductIndexingException(
                ProductIndexingException::ERROR_INVALID_RESULT,
                new Phrase('The AI shopping assistant reindex outcome is invalid.'),
                null
            );
        }
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function metrics(): RebuildMetricsInterface
    {
        return $this->metrics;
    }

    public function activated(): bool
    {
        return $this->outcome === self::OUTCOME_ACTIVATED;
    }

    public function noOp(): bool
    {
        return $this->outcome === self::OUTCOME_NO_OP;
    }

    public function aborted(): bool
    {
        return $this->outcome === self::OUTCOME_ABORTED;
    }
}
