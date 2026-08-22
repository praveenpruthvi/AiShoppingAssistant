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
 * Wraps the input, button, and status text in a flex row (see
 * `ColorPickerField`'s own docblock for why `min-width:0` on the
 * input's wrapping span, shared verbatim between the two classes, is
 * required) rather than relying on natural inline flow: Magento's
 * native `.input-text` admin styling is full-width, so without a flex
 * wrapper the button has no room left on the same line and wraps onto
 * its own row below — a real, screenshot-confirmed layout bug, not a
 * hypothetical one.
 *
 * A second, real, screenshot-confirmed flex bug: once the status text
 * is populated (e.g. "Found 3 model(s) — pick one from the Model field
 * suggestions."), the row stayed single-line (`flex-wrap` was never
 * set, so it defaults to `nowrap`) and the status span's flex-basis
 * (its own natural, unwrapped text width — often wider than the row)
 * dominated the shrink distribution entirely, since the input's own
 * `flex:1` shorthand gives it `flex-basis:0%` — a 0 basis contributes
 * nothing to the shrink calculation, so the input received ZERO of the
 * row's width once shrinking was in play, collapsing to 0px and making
 * the button appear to jump left into the input's place. Fixed by
 * letting the status span wrap onto its OWN full-width line
 * (`flex-basis:100%` under `flex-wrap:wrap`) instead of ever competing
 * with the input for width on the same line — the input/button pair
 * then always lays out exactly as it does with no status text at all,
 * regardless of how long the message is. Gated behind a `:empty` CSS
 * rule (the status span starts with no text node at all, so it starts
 * `display:none` and takes no layout space or line-wrap at all) so
 * this changes nothing visually before the button's first click —
 * `flex-basis:100%` only takes effect once real text is actually
 * present.
 */
class OllamaModelField extends Field
{
    /**
     * INPUT_WRAPPER_STYLE is kept identical to ColorPickerField's own —
     * both fields must lay out their input + trailing control the same
     * way. `gap:10px` is the one explicit spacing value between the
     * input and the button/status text. INLINE_ROW_STYLE additionally
     * carries `flex-wrap:wrap` here, unlike ColorPickerField's — this
     * field's trailing status text is dynamic, unbounded-length content
     * that a fixed-size swatch never needs to account for (see this
     * class's own docblock for the real bug that requires it).
     */
    private const INLINE_ROW_STYLE = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;';
    private const INPUT_WRAPPER_STYLE = 'flex:1;min-width:0;';

    /**
     * `flex:0 0 100%` forces the status span onto its own full-width
     * flex line (via the container's `flex-wrap:wrap`) once it has
     * content, so it never competes with the input for width on the
     * same line regardless of message length. The `:empty` CSS rule
     * (emitted alongside this in _getElementHtml()) overrides this to
     * `display:none` while the span has no text node yet — before the
     * button's first click, this changes nothing visually from the
     * plain input+button row.
     */
    private const STATUS_WRAPPER_STYLE = 'flex:0 0 100%;';

    protected function _getElementHtml(AbstractElement $element): string
    {
        $inputHtml = parent::_getElementHtml($element);

        $fieldId = $element->getHtmlId();
        $baseUrlFieldId = preg_replace('/_model$/', '_base_url', $fieldId) ?? $fieldId;
        $datalistId = $fieldId . '_datalist';
        $buttonId = $fieldId . '_fetch';
        $statusId = $fieldId . '_status';
        $fetchUrl = $this->getUrl('aavirbhava_aishoppingassistant/system_config/fetchOllamaModels');

        $html = '<style>#' . $this->escapeHtmlAttr($statusId) . ':empty{display:none;}</style>'
            . '<div class="aavirbhava-inline-field-row" style="' . self::INLINE_ROW_STYLE . '">'
            . '<span style="' . self::INPUT_WRAPPER_STYLE . '">' . $inputHtml . '</span>'
            . '<button type="button" id="' . $this->escapeHtmlAttr($buttonId) . '" '
            . 'class="action-secondary" style="flex:0 0 auto;">'
            . $this->escapeHtml(__('Fetch Ollama Models'))
            . '</button>'
            . '<span id="' . $this->escapeHtmlAttr($statusId) . '" style="' . self::STATUS_WRAPPER_STYLE . '"></span>'
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
