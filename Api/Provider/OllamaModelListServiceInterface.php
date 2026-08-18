<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\OllamaModelListResult;

interface OllamaModelListServiceInterface
{
    public function fetchModels(string $baseUrl, int $timeoutSeconds = 10): OllamaModelListResult;
}
