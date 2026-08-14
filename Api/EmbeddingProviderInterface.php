<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api;

use Aavirbhava\AiShoppingAssistant\Model\Dto\EmbeddingBatch;

interface EmbeddingProviderInterface
{
    /**
     * @param list<string> $texts
     */
    public function embed(array $texts): EmbeddingBatch;

    public function dimensions(): int;

    public function fingerprint(): string;
}
