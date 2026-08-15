<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Document;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;

/**
 * Immutable storage payload for the assistant index write path.
 *
 * id() is the transport _id and source() the persisted _source. An empty id is
 * rejected because it could never be written or verified deterministically.
 */
final class StoragePayload implements StoragePayloadInterface
{
    /**
     * @param array<string, mixed> $source
     */
    public function __construct(
        private readonly string $id,
        private readonly array $source
    ) {
        if ($id === '') {
            throw new IndexCompatibilityMismatchException();
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function source(): array
    {
        return $this->source;
    }
}
