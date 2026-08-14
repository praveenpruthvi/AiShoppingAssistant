<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config\Backend;

use Aavirbhava\AiShoppingAssistant\Model\Config\Backend\InvalidateProductIndex;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Magento\Framework\App\Cache\Type\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(InvalidateProductIndex::class)]
final class InvalidateProductIndexTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var TypeListInterface&MockObject
     */
    private $cacheTypeList;

    /**
     * @var IndexerRegistry&MockObject
     */
    private $indexerRegistry;

    /**
     * @var IndexerInterface&MockObject
     */
    private $indexer;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->indexer = $this->createMock(IndexerInterface::class);
    }

    private function buildModel(string $newValue, string $oldValue): InvalidateProductIndex
    {
        $eventManager = $this->createMock(EventManagerInterface::class);
        $cacheManager = $this->createMock(CacheInterface::class);
        $appState = $this->createMock(State::class);
        $logger = $this->createMock(LoggerInterface::class);
        $actionValidator = $this->createMock(RemoveAction::class);

        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($eventManager);
        $context->method('getCacheManager')->willReturn($cacheManager);
        $context->method('getAppState')->willReturn($appState);
        $context->method('getLogger')->willReturn($logger);
        $context->method('getActionValidator')->willReturn($actionValidator);

        $this->scopeConfig->method('getValue')->willReturn($oldValue);

        $model = new InvalidateProductIndex(
            $context,
            $this->createMock(Registry::class),
            $this->scopeConfig,
            $this->cacheTypeList,
            $this->indexerRegistry
        );
        $model->setData('path', 'aavirbhava_ai/general/searchable_attribute_codes');
        $model->setData('scope', 'default');
        $model->setData('scope_code', null);
        $model->setData('value', $newValue);

        return $model;
    }

    public function testInvalidatesAssistantIndexWhenValueChanged(): void
    {
        $this->indexerRegistry->expects(self::once())
            ->method('get')
            ->with(InvalidateProductIndex::INDEXER_ID)
            ->willReturn($this->indexer);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->buildModel('new', 'old')->afterSave();
    }

    public function testDoesNotInvalidateWhenValueUnchanged(): void
    {
        $this->indexerRegistry->expects(self::never())->method('get');

        $this->buildModel('same', 'same')->afterSave();
    }

    public function testInvalidatesConfigCacheOnlyWhenChanged(): void
    {
        $this->indexerRegistry->method('get')->willReturn($this->indexer);

        $this->cacheTypeList->expects(self::once())
            ->method('invalidate')
            ->with(Config::TYPE_IDENTIFIER);

        $this->buildModel('new', 'old')->afterSave();
    }

    public function testWrapsRegistryFailureInSanitizedConfigurationException(): void
    {
        $this->indexerRegistry->method('get')->willThrowException(
            new \RuntimeException('secret indexer trace')
        );

        try {
            $this->buildModel('new', 'old')->afterSave();
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString('secret indexer trace', $exception->getMessage());
        }
    }
}
