<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Block\Adminhtml\System\Config;

use Aavirbhava\AiShoppingAssistant\Block\Adminhtml\System\Config\ColorPickerField;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the appended markup wires a swatch/trigger to the real element id
 * and requires Magento's own shipped `jquery/colorpicker/js/colorpicker`
 * module (not a bundled/custom picker) — not that the picker JS actually
 * runs, the same "unit-test what's safe to unit-test, live-verify the
 * rest" split OllamaModelFieldTest already uses for this module's other
 * admin-JS field.
 */
#[CoversClass(ColorPickerField::class)]
final class ColorPickerFieldTest extends TestCase
{
    protected function setUp(): void
    {
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

    public function testAppendsASwatchBoundToTheFieldAndRequiresTheRealColorpickerModule(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#1979c3');

        self::assertStringContainsString('id="ai_shopping_assistant_appearance_primary_color_swatch"', $html);
        self::assertStringContainsString("'jquery/colorpicker/js/colorpicker'", $html);
        self::assertStringContainsString("\$('#ai_shopping_assistant_appearance_primary_color')", $html);
        self::assertStringContainsString('.ColorPicker(', $html);
    }

    public function testSwatchStartsWithTheCurrentValueAsItsBackgroundColor(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#8e44ad');

        // escapeHtmlAttr() hex-encodes '#' in an HTML attribute value
        // (Zend Escaper's standard attribute-context escaping), so the
        // literal character never appears — only its escaped form.
        self::assertStringContainsString('background-color:&#x23;8e44ad', $html);
    }

    public function testSwatchStartsTransparentWhenTheFieldHasNoValidValue(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '');

        self::assertStringContainsString('background-color:transparent', $html);
    }

    public function testIncludesTheRealElementHtmlFromTheParentRendererUnmodified(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#1979c3');

        self::assertStringContainsString(
            '<input id="ai_shopping_assistant_appearance_primary_color" data-original="true"/>',
            $html
        );
    }

    private function renderedHtml(string $htmlId, string $value): string
    {
        $objectManager = new ObjectManager($this);

        $context = $objectManager->getObject(Context::class, [
            'escaper' => new Escaper(),
            'urlBuilder' => $this->createMock(UrlInterface::class),
        ]);

        $block = $objectManager->getObject(ColorPickerField::class, ['context' => $context]);

        // getValue() is a DataObject magic accessor (not a declared
        // method), so createMock() alone can't stub it — addMethods() is
        // required, the same pattern this module's other tests already
        // use for magic accessors (e.g. LiveRevalidationServiceTest's
        // setCustomerGroupId()).
        $element = $this->getMockBuilder(AbstractElement::class)
            ->onlyMethods(['getHtmlId', 'getElementHtml'])
            ->addMethods(['getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $element->method('getHtmlId')->willReturn($htmlId);
        $element->method('getValue')->willReturn($value);
        $element->method('getElementHtml')->willReturn('<input id="' . $htmlId . '" data-original="true"/>');

        $reflection = new \ReflectionMethod(ColorPickerField::class, '_getElementHtml');
        $reflection->setAccessible(true);

        return $reflection->invoke($block, $element);
    }
}
