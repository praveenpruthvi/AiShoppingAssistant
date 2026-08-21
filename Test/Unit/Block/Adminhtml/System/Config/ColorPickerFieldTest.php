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
use Magento\Framework\View\Asset\Repository as AssetRepository;
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
 *
 * testEmitsTheRealColorpickerStylesheetLink() is the regression test for
 * the actual root-cause fix: the picker's own JS was always correct, but
 * its required CSS (`jquery/colorpicker/css/colorpicker.css`, the same
 * file Magento_Swatches' own layout XML loads) was never present on this
 * page — a real "click does nothing" bug live-diagnosed by reading
 * Magento_Swatches' own layout XML, not by guessing.
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

    public function testEmitsTheRealColorpickerStylesheetLink(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#1979c3');

        self::assertStringContainsString('<link rel="stylesheet" type="text/css" href="', $html);
        // escapeHtmlAttr() encodes ':' and '/' in an HTML attribute value
        // (Zend Escaper's standard attribute-context escaping), the same
        // reason '#' appears encoded elsewhere in this test file.
        self::assertStringContainsString(
            'https&#x3A;&#x2F;&#x2F;admin.test&#x2F;static&#x2F;jquery&#x2F;colorpicker&#x2F;css&#x2F;colorpicker.css',
            $html
        );
    }

    public function testInputAndSwatchShareOneFlexRowWithA10pxGap(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#1979c3');

        // Magento's native .input-text styling is full-width, so without
        // this flex wrapper the swatch has no room on the same line and
        // wraps onto its own row below (a real, screenshot-confirmed bug)
        // — shared verbatim with OllamaModelField's own input+button row.
        self::assertStringContainsString(
            '<div class="aavirbhava-inline-field-row" style="display:flex;align-items:center;gap:10px;">',
            $html
        );
        self::assertStringContainsString('<span style="flex:1;min-width:0;">', $html);
    }

    public function testInputWrapperAndSwatchAreInsideTheSameFlexRow(): void
    {
        $html = $this->renderedHtml('ai_shopping_assistant_appearance_primary_color', '#1979c3');

        $rowStart = strpos($html, '<div class="aavirbhava-inline-field-row"');
        $rowEnd = strpos($html, '</div>', $rowStart);

        self::assertNotFalse($rowStart);
        self::assertNotFalse($rowEnd);
        self::assertGreaterThan($rowStart, strpos($html, 'data-original="true"'));
        self::assertLessThan($rowEnd, strpos($html, 'data-original="true"'));
        self::assertGreaterThan($rowStart, strpos($html, 'aavirbhava-colorpicker-swatch'));
        self::assertLessThan($rowEnd, strpos($html, 'aavirbhava-colorpicker-swatch'));
    }

    private function renderedHtml(string $htmlId, string $value): string
    {
        $objectManager = new ObjectManager($this);

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->method('getUrlWithParams')
            ->with('jquery/colorpicker/css/colorpicker.css', self::isType('array'))
            ->willReturn('https://admin.test/static/jquery/colorpicker/css/colorpicker.css');

        $context = $objectManager->getObject(Context::class, [
            'escaper' => new Escaper(),
            'urlBuilder' => $this->createMock(UrlInterface::class),
            'assetRepo' => $assetRepository,
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
