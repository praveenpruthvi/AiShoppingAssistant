<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexConsumer;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexQueue;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\MagentoIncrementalProductIndexScheduler;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\UnavailableIncrementalProductIndexScheduler;
use PHPUnit\Framework\TestCase;

final class IncrementalQueueConfigTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../..';

    public function testCommunicationTopicMatchesQueueContract(): void
    {
        $xml = $this->loadXml('etc/communication.xml');
        $topic = $xml->xpath('/config/topic')[0] ?? null;
        self::assertNotNull($topic);
        self::assertSame(IncrementalProductIndexQueue::TOPIC, (string)$topic['name']);
        self::assertSame('false', (string)$topic['is_synchronous']);
        self::assertSame('string', (string)$topic['request']);

        $handler = $topic->handler[0] ?? null;
        self::assertNotNull($handler);
        self::assertSame(IncrementalProductIndexConsumer::class, (string)$handler['type']);
        self::assertSame('process', (string)$handler['method']);
    }

    public function testPublisherTopologyAndConsumerUseSameTopicAndQueue(): void
    {
        $publisher = $this->loadXml('etc/queue_publisher.xml')->xpath('/config/publisher')[0] ?? null;
        self::assertNotNull($publisher);
        self::assertSame(IncrementalProductIndexQueue::TOPIC, (string)$publisher['topic']);
        self::assertSame('', (string)$publisher['queue']);
        self::assertSame('', (string)$publisher['connection']);

        $binding = $this->loadXml('etc/queue_topology.xml')->xpath('/config/exchange/binding')[0] ?? null;
        self::assertNotNull($binding);
        self::assertSame(IncrementalProductIndexQueue::TOPIC, (string)$binding['topic']);
        self::assertSame(IncrementalProductIndexQueue::QUEUE, (string)$binding['destination']);

        $consumer = $this->loadXml('etc/queue_consumer.xml')->xpath('/config/consumer')[0] ?? null;
        self::assertNotNull($consumer);
        self::assertSame(IncrementalProductIndexQueue::CONSUMER, (string)$consumer['name']);
        self::assertSame(IncrementalProductIndexQueue::QUEUE, (string)$consumer['queue']);
        self::assertSame(IncrementalProductIndexConsumer::class . '::process', (string)$consumer['handler']);
    }

    public function testProductionSchedulerPreferenceRemainsFailClosedUntilDurableRecovery(): void
    {
        $preference = $this->loadXml('etc/di.xml')->xpath(
            '/config/preference[@for="' . IncrementalProductIndexSchedulerInterface::class . '"]'
        )[0] ?? null;

        self::assertNotNull($preference);
        self::assertSame(UnavailableIncrementalProductIndexScheduler::class, (string)$preference['type']);
    }

    public function testStagedMagentoQueueSchedulerRemainsDirectlyAvailable(): void
    {
        self::assertContains(
            IncrementalProductIndexSchedulerInterface::class,
            class_implements(MagentoIncrementalProductIndexScheduler::class)
        );
    }

    public function testUnavailableSchedulerRemainsExplicitProductionFallback(): void
    {
        self::assertContains(
            IncrementalProductIndexSchedulerInterface::class,
            class_implements(UnavailableIncrementalProductIndexScheduler::class)
        );
    }

    public function testModuleDeclaresMessageQueueDependency(): void
    {
        $module = $this->loadXml('etc/module.xml');
        self::assertNotFalse($module->xpath('/config/module/sequence/module[@name="Magento_MessageQueue"]'));

        $composer = json_decode((string)file_get_contents(self::MODULE_DIR . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertArrayHasKey('magento/module-message-queue', $composer['require'] ?? []);
    }

    private function loadXml(string $relativePath): \SimpleXMLElement
    {
        $path = self::MODULE_DIR . '/' . $relativePath;
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $path);

        return $xml;
    }
}
