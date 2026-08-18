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
 */
class ColorPickerField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = parent::_getElementHtml($element);

        $fieldId = $element->getHtmlId();
        $swatchId = $fieldId . '_swatch';
        $currentValue = trim((string) $element->getValue());
        $swatchBackground = preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $currentValue) === 1
            ? $currentValue
            : 'transparent';

        $html .= ' <span id="' . $this->escapeHtmlAttr($swatchId) . '" '
            . 'class="aavirbhava-colorpicker-swatch" '
            . 'title="' . $this->escapeHtmlAttr((string) __('Pick a color')) . '" '
            . 'style="display:inline-block;width:24px;height:24px;vertical-align:middle;margin-left:8px;'
            . 'border:1px solid #adadad;border-radius:3px;cursor:pointer;background-color:'
            . $this->escapeHtmlAttr($swatchBackground) . ';"></span>';

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
