<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * One document prepared for the assistant index storage layer.
 *
 * The transport id is kept separate from the persisted _source so the write
 * client can build the bulk metadata line from id() and the document body from
 * source() without ever placing a control field inside the stored document.
 */
interface StoragePayloadInterface
{
    /**
     * Stable document id used as the OpenSearch _id for upserts.
     */
    public function id(): string;

    /**
     * Flat, mapping-compatible _source body. Never contains transport control
     * fields such as _id or _index.
     *
     * @return array<string, mixed>
     */
    public function source(): array;
}
