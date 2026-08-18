<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\OutputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseParser;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputValidator::class)]
final class OutputValidatorTest extends TestCase
{
    private function response(array $body): ChatResponse
    {
        return new ChatResponse(json_encode($body), [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5);
    }

    private function verified(string $sku = 'SKU-1'): RevalidatedProduct
    {
        return new RevalidatedProduct(1, $sku, 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
    }

    private function validator(): OutputValidator
    {
        return new OutputValidator(new LlmResponseParser());
    }

    public function testValidResponseWithAVerifiedSkuProducesTheContract(): void
    {
        $response = $this->response([
            'message' => 'Here is a great fit.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => ['What size?'],
            'actions' => [['type' => 'compare', 'skus' => ['SKU-1']]],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
        $contract = $result->response();
        self::assertSame('Here is a great fit.', $contract->message);
        self::assertCount(1, $contract->products);
        self::assertSame('SKU-1', $contract->products[0]->product->sku);
        self::assertSame('Waterproof.', $contract->products[0]->reason);
        self::assertSame('organic', $contract->products[0]->recommendationType);
        self::assertSame(['What size?'], $contract->followUpQuestions);
        self::assertCount(1, $contract->actions);
        self::assertSame('openai', $contract->metadata->provider);
        self::assertSame('gpt-4o-mini', $contract->metadata->model);
        self::assertFalse($contract->metadata->fallbackUsed);
    }

    public function testEmptyProductListIsValid(): void
    {
        $response = $this->response([
            'message' => "I couldn't find a match, could you say more about what you need?",
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, []);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->response()->products);
    }

    public function testMalformedJsonIsInvalid(): void
    {
        $response = new ChatResponse('not json', [], new TokenUsage(0, 0), 'openai', 'gpt-4o-mini', 1);

        $result = $this->validator()->validate($response, []);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_MALFORMED_RESPONSE, $result->reasonCode());
    }

    public function testFabricatedSkuInProductsIsInvalid(): void
    {
        $response = $this->response([
            'message' => 'Here is a great fit.',
            'product_skus' => [['sku' => 'SKU-DOES-NOT-EXIST', 'reason' => 'made up']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_SKU, $result->reasonCode());
    }

    public function testFabricatedSkuInActionsIsInvalidEvenWhenProductsAreClean(): void
    {
        $response = $this->response([
            'message' => 'Here is a great fit.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [['type' => 'compare', 'skus' => ['SKU-1', 'SKU-FABRICATED']]],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_SKU, $result->reasonCode());
    }

    public function testOneFabricatedProductInvalidatesTheWholeResponseNotJustThatEntry(): void
    {
        $response = $this->response([
            'message' => 'Here are two options.',
            'product_skus' => [
                ['sku' => 'SKU-1', 'reason' => 'Real.'],
                ['sku' => 'SKU-FABRICATED', 'reason' => 'Fake.'],
            ],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
    }

    public function testUrlInMessageIsInvalid(): void
    {
        $response = $this->response([
            'message' => 'Check it out here: https://store.test/blue-shoe',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, []);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_URL, $result->reasonCode());
    }

    public function testUsesTheEmbeddedRevalidatedProductNotAnythingFromTheLlmForProductFacts(): void
    {
        $response = $this->response([
            'message' => 'Here is a great fit.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);
        $verified = $this->verified();

        $result = $this->validator()->validate($response, [$verified]);

        self::assertSame($verified, $result->response()->products[0]->product);
    }

    public function testNoPriceMentionedIsUnaffected(): void
    {
        $response = $this->response([
            'message' => 'Here is a great fit for you.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testExactDollarSignPriceMatchIsAccepted(): void
    {
        $response = $this->response([
            'message' => 'This one is $49.99 and fits your needs.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testCasualRoundedPriceWithinToleranceIsAccepted(): void
    {
        // Actual price 49.99; "about $50" is 0.01 away — well within the
        // 0.50 nearest-dollar-rounding tolerance.
        $response = $this->response([
            'message' => 'This one is about $50 and fits your needs.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testWordFormPriceMatchIsAccepted(): void
    {
        $response = $this->response([
            'message' => 'This one costs 49.99 dollars.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testSpecialPriceIsAlsoAnAcceptedMatch(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, 39.99, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $response = $this->response([
            'message' => 'This one is on sale for $39.99 right now.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'On sale.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$product]);

        self::assertTrue($result->isValid());
    }

    public function testFabricatedPriceInMessageIsInvalid(): void
    {
        $response = $this->response([
            'message' => "That's a great choice, and it's only $25!",
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_PRICE, $result->reasonCode());
    }

    public function testFabricatedPriceInWordFormIsInvalid(): void
    {
        $response = $this->response([
            'message' => 'It costs about 25 dollars.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_PRICE, $result->reasonCode());
    }

    public function testMentionedPriceMatchingAnyVerifiedProductPassesEvenIfNotTheOneRecommended(): void
    {
        // The regex pass can't attribute a number to a specific product —
        // it only checks that *some* revalidated candidate has that price.
        $other = new RevalidatedProduct(2, 'SKU-2', 'Red Hat', 25.00, null, 'https://store.test/red-hat', '2026-08-16T00:00:00+00:00');
        $response = $this->response([
            'message' => 'This one is $25, a great deal.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified(), $other]);

        self::assertTrue($result->isValid());
    }

    public function testFallbackUsedFlagIsPopulatedFromTheChatResponse(): void
    {
        $response = new ChatResponse(
            json_encode(['message' => 'ok', 'product_skus' => [], 'follow_up_questions' => [], 'actions' => []]),
            [],
            new TokenUsage(1, 1),
            'openai_compatible',
            'local-model',
            5,
            true
        );

        $result = $this->validator()->validate($response, []);

        self::assertTrue($result->isValid());
        self::assertTrue($result->response()->metadata->fallbackUsed);
        self::assertSame('openai_compatible', $result->response()->metadata->provider);
    }

    public function testThresholdQualifiedCurrencyMentionIsNoLongerAFalsePositive(): void
    {
        // Previously a documented, accepted false positive: a regex pass
        // can't distinguish a genuine product-price claim from a shipping
        // threshold that happens to use the same "$NN" phrasing, so this
        // used to reject the response even though nothing was fabricated
        // (the message never claims SKU-1 costs $75). Now fixed: "over"
        // immediately before "$75" marks it as a threshold, not a price
        // claim, so it's exempted from the check entirely.
        $response = $this->response([
            'message' => 'Great choice! You also get free shipping on orders over $75.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testKnownFalsePositiveUnqualifiedCurrencyMentionIsStillRejected(): void
    {
        // Still a documented, accepted limitation: a bare currency mention
        // with no recognized threshold word in front of it (a discount
        // amount, here) can't be told apart from a genuine price claim by
        // a regex pass alone, so it's still rejected even though nothing
        // was actually fabricated.
        $response = $this->response([
            'message' => 'Great choice! Use code SAVE5 for $5 off your order.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_PRICE, $result->reasonCode());
    }

    public function testPriceConstrainedSearchReplyEchoingTheCustomersThresholdIsValid(): void
    {
        // The exact real-world failure this fix addresses, reproduced
        // live: search_products has no structured price-filter parameter,
        // so the model restates the customer's own budget in its reply
        // while also stating the one real matching product's real price.
        // Before this fix, "$40" (the echoed threshold, mentioned twice)
        // had no matching real product price and rejected the entire
        // response outright — even though the $32 real price was correct.
        $product = new RevalidatedProduct(1, 'WJ09', 'Jade Yoga Jacket', 32.00, null, 'https://store.test/jade-yoga-jacket', '2026-08-16T00:00:00+00:00');
        $response = $this->response([
            'message' => "Based on my search, here's the jacket currently available under \$40:\n\n"
                . "- Jade Yoga Jacket (WJ09) - \$32\n\n"
                . 'This is currently the only jacket in our catalogue priced under $40.',
            'product_skus' => [['sku' => 'WJ09', 'reason' => 'Under $40.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$product]);

        self::assertTrue($result->isValid());
    }

    public function testLessThanPhrasingIsRecognizedAsAThreshold(): void
    {
        $response = $this->response([
            'message' => 'I found options less than $40, though none match your other criteria.',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertTrue($result->isValid());
    }

    public function testThresholdExemptionDoesNotSuppressARealFabricatedPriceElsewhereInTheMessage(): void
    {
        // The threshold exemption is scoped to the one qualified mention —
        // an unrelated, unqualified price fabricated elsewhere in the same
        // message must still be caught.
        $response = $this->response([
            'message' => 'I found jackets under $40. By the way, the Blue Shoe is $99.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $this->validator()->validate($response, [$this->verified()]);

        self::assertFalse($result->isValid());
        self::assertSame(OutputValidator::REASON_FABRICATED_PRICE, $result->reasonCode());
    }
}
