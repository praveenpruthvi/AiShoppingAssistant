<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Appends a "Fetch Ollama Models" button next to the llm/model and
 * fallback/model config fields (via <frontend_model> in system.xml) —
 * this module's total absence of ui_component/knockout admin form
 * customization elsewhere (confirmed by inspection before writing this)
 * ruled out a real dependent-field/dynamic-select UI component as
 * disproportionate for one field; a plain jQuery button + AJAX call
 * matches the only existing admin-JS precedent in this module
 * (Block\Adminhtml\Playground\Index's own Test Connection button, Task
 * 9) exactly.
 *
 * Deliberately does NOT replace the field with a real <select>: Magento's
 * config form fields render as plain text inputs with no built-in
 * mechanism for a dynamically-populated option list, and the model name
 * the admin wants may not be in the fetched list yet (a model pulled
 * after the page loaded, or a typo they want to fix) — an HTML5
 * <datalist> bound to the existing text input keeps free-text entry
 * fully intact while offering real, fetched suggestions, which degrades
 * gracefully to "just a text field" if the fetch fails or hasn't been
 * run yet.
 *
 * Wraps the input, button, and status text in a flex row
 * (`INLINE_ROW_STYLE`, shared verbatim with `ColorPickerField` — see
 * that class's own docblock for why `min-width:0` on the input's
 * wrapping span is required) rather than relying on natural inline
 * flow: Magento's native `.input-text` admin styling is full-width, so
 * without a flex wrapper the button has no room left on the same line
 * and wraps onto its own row below — a real, screenshot-confirmed
 * layout bug, not a hypothetical one.
 */
class OllamaModelField extends Field
{
    /**
     * Kept identical to ColorPickerField::INLINE_ROW_STYLE/
     * INPUT_WRAPPER_STYLE — both fields must lay out their input +
     * trailing control the same way. `gap:10px` is the one explicit
     * spacing value between the input and the button/status text.
     */
    private const INLINE_ROW_STYLE = 'display:flex;align-items:center;gap:10px;';
    private const INPUT_WRAPPER_STYLE = 'flex:1;min-width:0;';

    protected function _getElementHtml(AbstractElement $element): string
    {
        $inputHtml = parent::_getElementHtml($element);

        $fieldId = $element->getHtmlId();
        $baseUrlFieldId = preg_replace('/_model$/', '_base_url', $fieldId) ?? $fieldId;
        $datalistId = $fieldId . '_datalist';
        $buttonId = $fieldId . '_fetch';
        $statusId = $fieldId . '_status';
        $fetchUrl = $this->getUrl('aavirbhava_aishoppingassistant/system_config/fetchOllamaModels');

        $html = '<div class="aavirbhava-inline-field-row" style="' . self::INLINE_ROW_STYLE . '">'
            . '<span style="' . self::INPUT_WRAPPER_STYLE . '">' . $inputHtml . '</span>'
            . '<button type="button" id="' . $this->escapeHtmlAttr($buttonId) . '" '
            . 'class="action-secondary" style="flex:0 0 auto;">'
            . $this->escapeHtml(__('Fetch Ollama Models'))
            . '</button>'
            . '<span id="' . $this->escapeHtmlAttr($statusId) . '" style="flex:0 1 auto;"></span>'
            . '</div>'
            . '<datalist id="' . $this->escapeHtmlAttr($datalistId) . '"></datalist>';

        $html .= '<script>' . $this->fetchModelsScript(
            $fieldId,
            $baseUrlFieldId,
            $buttonId,
            $statusId,
            $datalistId,
            $fetchUrl
        ) . '</script>';

        return $html;
    }

    private function fetchModelsScript(
        string $fieldId,
        string $baseUrlFieldId,
        string $buttonId,
        string $statusId,
        string $datalistId,
        string $fetchUrl
    ): string {
        return <<<JS
require(['jquery'], function (\$) {
    'use strict';
    \$('#{$fieldId}').attr('list', '{$datalistId}');
    \$('#{$buttonId}').on('click', function () {
        var \$button = \$(this);
        var \$status = \$('#{$statusId}');
        var \$datalist = \$('#{$datalistId}');
        var baseUrl = \$('#{$baseUrlFieldId}').val() || '';

        \$button.prop('disabled', true);
        \$status.text('{$this->escapeJs((string) __('Fetching…'))}');

        \$.ajax({
            url: '{$this->escapeJs($fetchUrl)}',
            type: 'POST',
            dataType: 'json',
            data: {base_url: baseUrl, form_key: '{$this->escapeJs($this->getFormKey())}'}
        }).done(function (response) {
            \$datalist.empty();
            if (!response.successful) {
                \$status.text('✗ ' + response.message);
                return;
            }
            if (response.models.length === 0) {
                \$status.text('{$this->escapeJs((string) __('No models found — pull one with `ollama pull <model>` first.'))}');
                return;
            }
            response.models.forEach(function (name) {
                \$datalist.append(\$('<option>').val(name));
            });
            \$status.text(
                '{$this->escapeJs((string) __('Found'))} ' + response.models.length
                + ' {$this->escapeJs((string) __('model(s) — pick one from the Model field suggestions.'))}'
            );
        }).fail(function () {
            \$status.text('{$this->escapeJs((string) __('Request failed.'))}');
        }).always(function () {
            \$button.prop('disabled', false);
        });
    });
});
JS;
    }
}
