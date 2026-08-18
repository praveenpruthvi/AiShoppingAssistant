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
 */
class OllamaModelField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = parent::_getElementHtml($element);

        $fieldId = $element->getHtmlId();
        $baseUrlFieldId = preg_replace('/_model$/', '_base_url', $fieldId) ?? $fieldId;
        $datalistId = $fieldId . '_datalist';
        $buttonId = $fieldId . '_fetch';
        $statusId = $fieldId . '_status';
        $fetchUrl = $this->getUrl('aavirbhava_aishoppingassistant/system_config/fetchOllamaModels');

        $html .= '<button type="button" id="' . $this->escapeHtmlAttr($buttonId) . '" '
            . 'class="action-secondary" style="margin-left:8px;">'
            . $this->escapeHtml(__('Fetch Ollama Models'))
            . '</button> '
            . '<span id="' . $this->escapeHtmlAttr($statusId) . '"></span>'
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
