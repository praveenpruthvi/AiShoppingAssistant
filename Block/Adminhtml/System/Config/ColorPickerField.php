<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Appends a real color-swatch trigger next to each Appearance color
 * field (via <frontend_model> in system.xml), wired to Magento's own
 * shipped jQuery colorpicker widget (`jquery/colorpicker/js/colorpicker`,
 * the same one `Magento_Swatches`' admin "Visual Swatch" attribute
 * editor already uses) — not a custom-built picker.
 *
 * Matches the existing `OllamaModelField` convention exactly: a plain
 * text input (so a value typed or pasted directly still works and still
 * round-trips through the exact same `ConfigurationReader::readColor()`
 * validation as before) plus a small inline `<script>` tag, the only
 * admin-JS pattern this module uses — no new UI-component/Knockout
 * machinery for one field.
 *
 * Root-caused a real "clicking does nothing" bug here (confirmed by
 * reading Magento_Swatches' own layout XML, `catalog_product_attribute_
 * edit.xml`, which explicitly loads `jquery/colorpicker/css/
 * colorpicker.css` alongside the JS): the JS binding below was always
 * correct (`colorpicker.js` is genuinely AMD-wrapped, no shim needed,
 * and the swatch element already exists in the DOM by the time this
 * inline script runs), but this module's System Configuration page
 * never loaded the picker's own required CSS anywhere — no layout XML
 * in this module referenced it, and it is not loaded globally by any
 * core adminhtml layout. Without it, `.colorpicker`'s real rule set
 * (`position: absolute; ... display: none;`) never applies, so the
 * popup's DOM (built correctly by the plugin on click) renders with
 * browser table_default block-flow instead — visually indistinguishable
 * from "nothing happened" even though the click handler fired. Fixed by
 * emitting the real stylesheet's URL via this block's own inherited
 * `getViewFileUrl()` (the same file id Swatches' layout XML declares),
 * not the paired `Magento_Swatches::css/swatches.css` skin override —
 * that one only re-themes colors/fonts on top of an already-functional
 * picker and would add a real dependency on Magento_Swatches being
 * enabled for no functional benefit.
 *
 * Wraps the input and the swatch in a flex row (`INLINE_ROW_STYLE`,
 * shared verbatim with `OllamaModelField`) rather than relying on the
 * input's own natural inline flow: Magento's native `.input-text`
 * admin styling is full-width, so without a flex wrapper the swatch
 * has no room left on the same line and wraps onto its own row below
 * — a real, screenshot-confirmed layout bug, not a hypothetical one.
 * `min-width:0` on the input's own wrapping span is required for the
 * flex item to actually shrink below the input's intrinsic 100% width
 * (the standard flexbox "flex item ignores width:100% without this"
 * gotcha) — omitting it silently reintroduces the same wrap bug.
 */
class ColorPickerField extends Field
{
    /**
     * Shared with OllamaModelField's own input+trailing-control row —
     * both must stay identical so a color field and a model field lay
     * out the same way. `gap:10px` is the one explicit spacing value
     * this pairs with the trailing control, replacing the old
     * margin-left/vertical-align approach entirely (flexbox `align-
     * items:center` centers both children natively, no vertical-align
     * needed).
     */
    private const INLINE_ROW_STYLE = 'display:flex;align-items:center;gap:10px;';
    private const INPUT_WRAPPER_STYLE = 'flex:1;min-width:0;';

    protected function _getElementHtml(AbstractElement $element): string
    {
        $inputHtml = parent::_getElementHtml($element);

        $fieldId = $element->getHtmlId();
        $swatchId = $fieldId . '_swatch';
        $currentValue = trim((string) $element->getValue());
        $swatchBackground = preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $currentValue) === 1
            ? $currentValue
            : 'transparent';

        $swatchHtml = '<span id="' . $this->escapeHtmlAttr($swatchId) . '" '
            . 'class="aavirbhava-colorpicker-swatch" '
            . 'title="' . $this->escapeHtmlAttr((string) __('Pick a color')) . '" '
            . 'style="display:inline-block;width:24px;height:24px;flex:0 0 auto;'
            . 'border:1px solid #adadad;border-radius:3px;cursor:pointer;background-color:'
            . $this->escapeHtmlAttr($swatchBackground) . ';"></span>';

        $html = '<link rel="stylesheet" type="text/css" href="'
            . $this->escapeHtmlAttr($this->getViewFileUrl('jquery/colorpicker/css/colorpicker.css')) . '">';

        $html .= '<div class="aavirbhava-inline-field-row" style="' . self::INLINE_ROW_STYLE . '">'
            . '<span style="' . self::INPUT_WRAPPER_STYLE . '">' . $inputHtml . '</span>'
            . $swatchHtml
            . '</div>';

        $html .= '<script>' . $this->colorPickerScript($fieldId, $swatchId) . '</script>';

        return $html;
    }

    private function colorPickerScript(string $fieldId, string $swatchId): string
    {
        return <<<JS
require(['jquery', 'jquery/colorpicker/js/colorpicker'], function (\$) {
    'use strict';

    var \$input = \$('#{$fieldId}');
    var \$swatch = \$('#{$swatchId}');

    function normalizedHex(value) {
        value = (value || '').trim();
        return (/^#[0-9a-fA-F]{6}$/).test(value) ? value.substring(1) : null;
    }

    \$swatch.ColorPicker({
        color: normalizedHex(\$input.val()) || 'cccccc',
        onChange: function (hsb, hex) {
            \$swatch.css('background-color', '#' + hex);
            \$input.val('#' + hex).trigger('change');
        },
        onSubmit: function (hsb, hex, rgb, pickerEl) {
            \$(pickerEl).ColorPickerHide();
        }
    });

    // Typing/pasting a value directly keeps working (the field stays a
    // real text input) — the swatch and the picker's own internal state
    // just need to stay in sync with whatever was typed.
    \$input.on('input change', function () {
        var hex = normalizedHex(\$(this).val());
        \$swatch.css('background-color', hex ? '#' + hex : 'transparent');
        if (hex) {
            \$swatch.ColorPickerSetColor(hex);
        }
    });
});
JS;
    }
}
