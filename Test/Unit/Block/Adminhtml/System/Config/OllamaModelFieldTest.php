<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\System\Config;

use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\System\Config\OllamaModelField;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the appended markup carries the right ids/wiring (button next to
 * the field, datalist bound via the fieldId_datalist convention,
 * base_url sibling field id derived by stripping the _model suffix) —
 * not that the JS actually runs (this file has no JS test tooling, same
 * limitation Task 11's report already stated plainly for the storefront
 * widget's JS).
 */
#[CoversClass(OllamaModelField::class)]
final class OllamaModelFieldTest extends TestCase
{
    protected function setUp(): void
    {
        // escapeHtmlAttr() (unlike escapeHtml(), which uses htmlspecialchars()
        // directly) internally resolves both a translate-inline checker and a
        // ZendEscaper through the static ObjectManager singleton — a bare
        // mock ZendEscaper's unstubbed escapeHtmlAttr() would silently return
        // '' (PHPUnit's default for an unconfigured string-returning method),
        // so it needs the same real-instance treatment Escaper itself gets.
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(function (string $type) {
            return match ($type) {
                Escaper::class => new Escaper(),
                ZendEscaper::class => new ZendEscaper(),
                default => $this->createMock($type),
            };
        });
        AppObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(AppObjectManager::class, '_instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);
    }

    public function testAppendsAFetchButtonBoundToTheSiblingBaseUrlField(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_llm_model');

        self::assertStringContainsString('id="ai_shopping_assistant_llm_model_fetch"', $html);
        self::assertStringContainsString('Fetch Ollama Models', $html);
        self::assertStringContainsString("\$('#ai_shopping_assistant_llm_model_datalist')", $html);
        self::assertStringContainsString("\$('#ai_shopping_assistant_llm_base_url').val()", $html);
        self::assertStringContainsString('<datalist id="ai_shopping_assistant_llm_model_datalist">', $html);
    }

    public function testWorksForTheFallbackGroupTooByDerivingItsOwnBaseUrlSibling(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_fallback_model');

        self::assertStringContainsString("\$('#ai_shopping_assistant_fallback_base_url').val()", $html);
    }

    public function testIncludesTheRealElementHtmlFromTheParentRendererUnmodified(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_llm_model');

        self::assertStringContainsString('<input id="ai_shopping_assistant_llm_model" data-original="true"/>', $html);
    }

    /**
     * Regression test for a real, screenshot-confirmed layout bug:
     * Magento's native .input-text styling is full-width, so without a
     * flex wrapper the button/status text have no room on the same line
     * and wrap onto their own row below the input. Shared verbatim with
     * ColorPickerField::INLINE_ROW_STYLE/INPUT_WRAPPER_STYLE so a color
     * field and a model field lay out identically.
     */
    public function testInputButtonAndStatusShareOneFlexRowWithA10pxGap(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_llm_model');

        self::assertStringContainsString(
            '<div class="aavirbhava-inline-field-row" style="display:flex;align-items:center;gap:10px;">',
            $html
        );
        self::assertStringContainsString('<span style="flex:1;min-width:0;">', $html);

        $rowStart = strpos($html, '<div class="aavirbhava-inline-field-row"');
        $rowEnd = strpos($html, '</div>', $rowStart);

        self::assertNotFalse($rowStart);
        self::assertNotFalse($rowEnd);
        foreach (['data-original="true"', 'id="ai_shopping_assistant_llm_model_fetch"', 'id="ai_shopping_assistant_llm_model_status"'] as $needle) {
            $position = strpos($html, $needle);
            self::assertNotFalse($position, "Expected to find {$needle}");
            self::assertGreaterThan($rowStart, $position);
            self::assertLessThan($rowEnd, $position);
        }
    }

    public function testDatalistStaysOutsideTheFlexRow(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_llm_model');

        $rowEnd = strpos($html, '</div>');
        $datalistPosition = strpos($html, '<datalist');

        self::assertNotFalse($rowEnd);
        self::assertNotFalse($datalistPosition);
        self::assertGreaterThan($rowEnd, $datalistPosition);
    }

    private function renderedHtml(string $htmlId): string
    {
        $objectManager = new ObjectManager($this);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://admin.test/aavirbhava_aishoppingassistant/system_config/fetchOllamaModels');

        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('test-form-key');

        $context = $objectManager->getObject(Context::class, [
            'escaper' => new Escaper(),
            'urlBuilder' => $urlBuilder,
            'formKey' => $formKey,
        ]);

        $block = $objectManager->getObject(OllamaModelField::class, ['context' => $context]);

        $element = $this->createMock(AbstractElement::class);
        $element->method('getHtmlId')->willReturn($htmlId);
        $element->method('getElementHtml')->willReturn('<input id="' . $htmlId . '" data-original="true"/>');

        $reflection = new \ReflectionMethod(OllamaModelField::class, '_getElementHtml');
        $reflection->setAccessible(true);

        return $reflection->invoke($block, $element);
    }
}
