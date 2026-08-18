<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatPipelineResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantAction;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ResponseMetadata;
use Aavirbhava\AiShoppingAssistant\Model\Chat\SafeResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatResponseSerializer::class)]
final class ChatResponseSerializerTest extends TestCase
{
    public function testSerializesAShortCircuitedResultWithEmptyCollections(): void
    {
        $result = ChatPipelineResult::shortCircuit(new SafeResponse('Please stay on topic.', 'off_topic_request'));

        $serialized = (new ChatResponseSerializer())->serialize($result);

        self::assertSame(
            [
                'message' => 'Please stay on topic.',
                'reason_code' => 'off_topic_request',
                'products' => [],
                'follow_up_questions' => [],
                'actions' => [],
                'metadata' => null,
                'awaiting_confirmation' => false,
            ],
            $serialized
        );
    }

    public function testSerializesAGeneratedResultWithLiveProductFactsAndMetadata(): void
    {
        $product = new RevalidatedProduct(
            1,
            'SKU-1',
            'Blue Shoe',
            49.99,
            39.99,
            'https://store.test/blue-shoe',
            '2026-08-16T00:00:00+00:00',
            'https://store.test/media/catalog/product/cache/blue-shoe.jpg'
        );

        $response = new AssistantResponse(
            'Here is a great option.',
            [new ProductResult($product, 'Matches your search.')],
            ['Would you like to see more colors?'],
            [new AssistantAction('compare', ['SKU-1', 'SKU-2'])],
            new ResponseMetadata('openai', 'gpt-4o-mini', false)
        );

        $serialized = (new ChatResponseSerializer())->serialize(ChatPipelineResult::generated($response));

        self::assertSame('Here is a great option.', $serialized['message']);
        self::assertNull($serialized['reason_code']);
        self::assertSame(
            [
                'sku' => 'SKU-1',
                'name' => 'Blue Shoe',
                'price' => 49.99,
                'special_price' => 39.99,
                'url' => 'https://store.test/blue-shoe',
                'image_url' => 'https://store.test/media/catalog/product/cache/blue-shoe.jpg',
                'verified_at' => '2026-08-16T00:00:00+00:00',
                'reason' => 'Matches your search.',
                'recommendation_type' => 'organic',
            ],
            $serialized['products'][0]
        );
        self::assertSame(['Would you like to see more colors?'], $serialized['follow_up_questions']);
        self::assertSame(['type' => 'compare', 'skus' => ['SKU-1', 'SKU-2']], $serialized['actions'][0]);
        self::assertSame(
            ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'fallback_used' => false],
            $serialized['metadata']
        );
        self::assertFalse($serialized['awaiting_confirmation']);
    }

    public function testSerializeDisplayPayloadMatchesTheProductsFollowUpsAndActionsSerializeProduces(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, 39.99, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $response = new AssistantResponse(
            'Here is a great option.',
            [new ProductResult($product, 'Matches your search.')],
            ['Would you like to see more colors?'],
            [new AssistantAction('compare', ['SKU-1', 'SKU-2'])],
            new ResponseMetadata('openai', 'gpt-4o-mini', false)
        );

        $serializer = new ChatResponseSerializer();
        $fullySerialized = $serializer->serialize(ChatPipelineResult::generated($response));
        $displayPayload = $serializer->serializeDisplayPayload($response);

        self::assertSame($fullySerialized['products'], $displayPayload['products']);
        self::assertSame($fullySerialized['follow_up_questions'], $displayPayload['follow_up_questions']);
        self::assertSame($fullySerialized['actions'], $displayPayload['actions']);
    }

    public function testSerializesAwaitingConfirmationWhenThePipelineResultCarriesIt(): void
    {
        $response = new AssistantResponse(
            'Would you like me to add that to your cart?',
            [],
            [],
            [],
            new ResponseMetadata('openai', 'gpt-4o-mini', false)
        );

        $serialized = (new ChatResponseSerializer())->serialize(ChatPipelineResult::generated($response, true));

        self::assertTrue($serialized['awaiting_confirmation']);
    }
}
