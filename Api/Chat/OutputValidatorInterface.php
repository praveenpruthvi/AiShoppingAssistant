<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\OutputValidationResult;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Gates every provider response before it can reach a customer: the
 * response must be well-formed structured output, must not leak a URL in
 * free text, and every SKU it mentions (in products or actions) must be
 * present in the already-live-revalidated set. Any single fabrication
 * invalidates the whole response — a response that hallucinated once isn't
 * trusted for the rest of its content either.
 */
interface OutputValidatorInterface
{
    /**
     * @param list<RevalidatedProduct> $verifiedProducts
     */
    public function validate(ChatResponse $response, array $verifiedProducts): OutputValidationResult;
}
