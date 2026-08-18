<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmResponseParser::class)]
final class LlmResponseParserTest extends TestCase
{
    public function testParsesAFullyPopulatedResponse(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'Here are some options.',
            'product_skus' => [['sku' => 'SKU-1', 'reason' => 'Waterproof.']],
            'follow_up_questions' => ['What size?'],
            'actions' => [['type' => 'compare', 'skus' => ['SKU-1', 'SKU-2']]],
        ]);

        $result = $parser->parse($json);

        self::assertNotNull($result);
        self::assertSame('Here are some options.', $result->message);
        self::assertSame([['sku' => 'SKU-1', 'reason' => 'Waterproof.']], $result->productSkus);
        self::assertSame(['What size?'], $result->followUpQuestions);
        self::assertSame([['type' => 'compare', 'skus' => ['SKU-1', 'SKU-2']]], $result->actions);
    }

    public function testParsesAMinimalResponseWithEmptyLists(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'No matches found.',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $parser->parse($json);

        self::assertNotNull($result);
        self::assertSame([], $result->productSkus);
        self::assertSame([], $result->followUpQuestions);
        self::assertSame([], $result->actions);
    }

    public function testInvalidJsonReturnsNull(): void
    {
        $parser = new LlmResponseParser();

        self::assertNull($parser->parse('not json at all'));
    }

    public function testStripsAWrappingMarkdownCodeFenceBeforeParsing(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'Here are some options.',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $parser->parse("```json\n{$json}\n```");

        self::assertNotNull($result);
        self::assertSame('Here are some options.', $result->message);
    }

    public function testStripsAWrappingCodeFenceWithNoLanguageTag(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'Here are some options.',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        $result = $parser->parse("```\n{$json}\n```");

        self::assertNotNull($result);
        self::assertSame('Here are some options.', $result->message);
    }

    public function testProseWithoutAnyJsonStillReturnsNull(): void
    {
        $parser = new LlmResponseParser();

        self::assertNull($parser->parse("Here are some options:\n- Item one\n- Item two"));
    }

    public function testNonObjectJsonReturnsNull(): void
    {
        $parser = new LlmResponseParser();

        self::assertNull($parser->parse('[1, 2, 3]'));
    }

    public function testMissingMessageReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode(['product_skus' => [], 'follow_up_questions' => [], 'actions' => []]);

        self::assertNull($parser->parse($json));
    }

    public function testEmptyMessageReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode(['message' => '', 'product_skus' => [], 'follow_up_questions' => [], 'actions' => []]);

        self::assertNull($parser->parse($json));
    }

    public function testMissingProductSkusKeyReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode(['message' => 'ok', 'follow_up_questions' => [], 'actions' => []]);

        self::assertNull($parser->parse($json));
    }

    public function testProductSkuEntryMissingReasonReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [['sku' => 'SKU-1']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        self::assertNull($parser->parse($json));
    }

    public function testProductSkuEntryWithEmptySkuReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [['sku' => '', 'reason' => 'x']],
            'follow_up_questions' => [],
            'actions' => [],
        ]);

        self::assertNull($parser->parse($json));
    }

    public function testFollowUpQuestionsWithNonStringEntryReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [],
            'follow_up_questions' => [42],
            'actions' => [],
        ]);

        self::assertNull($parser->parse($json));
    }

    public function testActionMissingTypeReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [['skus' => ['SKU-1']]],
        ]);

        self::assertNull($parser->parse($json));
    }

    public function testActionWithNonArraySkusReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [['type' => 'compare', 'skus' => 'SKU-1']],
        ]);

        self::assertNull($parser->parse($json));
    }

    public function testActionWithEmptySkuStringReturnsNull(): void
    {
        $parser = new LlmResponseParser();
        $json = json_encode([
            'message' => 'ok',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [['type' => 'compare', 'skus' => ['']]],
        ]);

        self::assertNull($parser->parse($json));
    }
}
