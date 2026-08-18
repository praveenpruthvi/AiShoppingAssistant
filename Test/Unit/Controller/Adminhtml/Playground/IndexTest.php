<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Controller\Adminhtml\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Playground\PlaygroundQueryRunnerInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Adminhtml\Playground\Index;
use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundResult;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\HttpRequestInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the controller stays thin: it only ever registers whatever
 * PlaygroundQueryRunnerInterface::run() (or the query-validation/
 * exception-handling wrapped around it) produces for
 * Block\Adminhtml\Playground\Index to read — no pipeline logic of its own.
 */
#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    private const STORE_ID = 3;

    public function testGetRendersThePageWithoutRunningAnyQuery(): void
    {
        $runner = $this->createMock(PlaygroundQueryRunnerInterface::class);
        $runner->expects(self::never())->method('run');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $controller = $this->controller(
            request: $this->request(isPost: false, params: []),
            runner: $runner,
            registry: $registry
        );

        self::assertInstanceOf(Page::class, $controller->execute());
    }

    public function testPostWithAnEmptyQueryRegistersAnErrorAndNeverCallsTheRunner(): void
    {
        $runner = $this->createMock(PlaygroundQueryRunnerInterface::class);
        $runner->expects(self::never())->method('run');

        $registry = $this->createMock(Registry::class);
        $registered = [];
        $registry->method('register')->willReturnCallback(
            function (string $key, $value) use (&$registered): void {
                $registered[$key] = $value;
            }
        );

        $controller = $this->controller(
            request: $this->request(isPost: true, params: ['query' => '  ', 'call_llm' => false]),
            runner: $runner,
            registry: $registry
        );

        $controller->execute();

        self::assertSame('Enter a query to run.', $registered[Index::REGISTRY_KEY_ERROR]);
        self::assertArrayNotHasKey(Index::REGISTRY_KEY_RESULT, $registered);
    }

    public function testPostWithAValidQueryRunsItAndRegistersTheResult(): void
    {
        $result = new PlaygroundResult(
            'red hats',
            self::STORE_ID,
            true,
            null,
            [],
            [],
            [],
            false,
            [],
            [],
            null,
            true,
            [],
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null
        );

        $runner = $this->createMock(PlaygroundQueryRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(self::STORE_ID, 'red hats', true)
            ->willReturn($result);

        $registry = $this->createMock(Registry::class);
        $registered = [];
        $registry->method('register')->willReturnCallback(
            function (string $key, $value) use (&$registered): void {
                $registered[$key] = $value;
            }
        );

        $controller = $this->controller(
            request: $this->request(isPost: true, params: ['query' => 'red hats', 'call_llm' => true]),
            runner: $runner,
            registry: $registry
        );

        $controller->execute();

        self::assertSame('red hats', $registered[Index::REGISTRY_KEY_QUERY]);
        self::assertTrue($registered[Index::REGISTRY_KEY_CALL_LLM]);
        self::assertSame($result, $registered[Index::REGISTRY_KEY_RESULT]);
        self::assertArrayNotHasKey(Index::REGISTRY_KEY_ERROR, $registered);
    }

    public function testRunnerThrowingALocalizedExceptionRegistersItsMessageInsteadOfPropagating(): void
    {
        $runner = $this->createMock(PlaygroundQueryRunnerInterface::class);
        $runner->method('run')->willThrowException(new LocalizedException(new Phrase('Store is not active.')));

        $registry = $this->createMock(Registry::class);
        $registered = [];
        $registry->method('register')->willReturnCallback(
            function (string $key, $value) use (&$registered): void {
                $registered[$key] = $value;
            }
        );

        $controller = $this->controller(
            request: $this->request(isPost: true, params: ['query' => 'red hats', 'call_llm' => false]),
            runner: $runner,
            registry: $registry
        );

        $controller->execute();

        self::assertSame('Store is not active.', $registered[Index::REGISTRY_KEY_ERROR]);
        self::assertArrayNotHasKey(Index::REGISTRY_KEY_RESULT, $registered);
    }

    /**
     * The controller needs both RequestInterface::getParam() and
     * HttpRequestInterface::isPost() — no single Magento interface (or
     * mockable class) exposes both without also pulling in a much heavier
     * concrete Request implementation, so a minimal stub covers exactly
     * what Controller\Adminhtml\Playground\Index actually calls.
     */
    private function request(bool $isPost, array $params): RequestInterface&HttpRequestInterface
    {
        return new class ($isPost, $params) implements RequestInterface, HttpRequestInterface {
            public function __construct(private readonly bool $isPost, private readonly array $params)
            {
            }

            public function isPost()
            {
                return $this->isPost;
            }

            public function isGet()
            {
                return !$this->isPost;
            }

            public function isPatch()
            {
                return false;
            }

            public function isDelete()
            {
                return false;
            }

            public function isPut()
            {
                return false;
            }

            public function isAjax()
            {
                return false;
            }

            public function getParam($key, $defaultValue = null)
            {
                return $this->params[$key] ?? $defaultValue;
            }

            public function getModuleName()
            {
                return null;
            }

            public function setModuleName($name)
            {
                return $this;
            }

            public function getActionName()
            {
                return null;
            }

            public function setActionName($name)
            {
                return $this;
            }

            public function setParams(array $params)
            {
                return $this;
            }

            public function getParams()
            {
                return $this->params;
            }

            public function getCookie($name, $default)
            {
                return $default;
            }

            public function isSecure()
            {
                return true;
            }
        };
    }

    private function controller(
        RequestInterface&HttpRequestInterface $request,
        PlaygroundQueryRunnerInterface $runner,
        Registry $registry
    ): Index {
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $title = $this->createMock(Title::class);
        $pageConfig = $this->createMock(Config::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createMock(BackendPage::class);
        $resultPage->method('setActiveMenu')->willReturnSelf();
        $resultPage->method('addBreadcrumb')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($resultPage);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Index($context, $pageFactory, $registry, $storeManager, $runner);
    }
}
