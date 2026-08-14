<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Catalog;

/**
 * Centralized schema version for the normalized product document.
 *
 * Bump VERSION when the persisted document shape, the embedding payload, or the
 * content hashing rules change in a way that invalidates already-indexed documents.
 * VERSION is independent of the module release version on purpose.
 */
final class ProductDocumentSchema
{
    public const VERSION = 1;
}
