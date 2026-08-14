<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api;

use Aavirbhava\AiShoppingAssistant\Model\Dto\EmbeddingBatch;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

interface EmbeddingProviderInterface
{
    /**
     * @param list<string> $texts
     */
    public function embed(array $texts): EmbeddingBatch;

    public function dimensions(): int;

    public function fingerprint(): string;

    public function capabilities(): ProviderCapabilities;
}
