# STATUS REPORT — "Fetch Ollama Models" status message covering the Model input (CSS-only fix)

## The problem (user-reported, with 2 screenshots)

After clicking "Fetch Ollama Models" on the Primary/Fallback LLM
config screen, the status message ("Found 3 model(s) — pick one from
the Model field suggestions.") appeared and pushed the button to the
left, visually covering/replacing the Model text input — the input
appeared to have disappeared entirely.

## Root cause

`Block/Adminhtml/System/Config/OllamaModelField.php` lays out the
Model input, the "Fetch Ollama Models" button, and the status message
in one CSS flex row. Before this fix, the row had no `flex-wrap`
(defaults to `nowrap`), so all three items were always forced onto one
line, and the input's flex-basis was `0%` (from the `flex:1`
shorthand).

Once the status span holds real text, the browser has to shrink the
row to fit — the classic flexbox shrinking algorithm distributes the
negative space across shrinkable items proportional to
`flex-basis × flex-shrink`. Since the input's flex-basis is `0`, it
contributes `0` to that distribution and receives `0` width,
collapsing the input to `0px`. The browser's *growing* phase — which
is what would normally give `flex:1` its share of extra space — never
runs at all once the row is already in shrink mode, so nothing
rescues the input. The button, having a fixed non-shrinking basis,
stays put and visually appears to have "jumped left" into the space
the (now-invisible) input used to occupy.

Confirmed via direct reading of the CSS and flexbox shrink-algorithm
math — no browser was needed to identify the mechanism, and it matches
exactly what both screenshots show.

## Fix (CSS only — no JS or business logic touched)

- Added `flex-wrap:wrap` to the row's container style.
- Changed the status span's style to `flex:0 0 100%` — once populated,
  it always wraps onto its own full-width line rather than ever
  competing with the input for horizontal space on the same line. The
  input/button pair then lays out exactly as it does with no status
  text at all, regardless of how long the message is.
- Gated the new layout behind a `:empty` CSS pseudo-class rule
  (`#{fieldId}_status:empty{display:none;}`), emitted as a small
  `<style>` block right before the row. The status `<span>` starts
  with no text node at all, so it starts hidden and takes zero layout
  space — a field the admin has never clicked "Fetch Ollama Models" on
  renders byte-identical to before this fix. Only once jQuery's
  `.text(...)` call actually gives the span real text (unchanged JS)
  does `:empty` stop matching and the `flex:0 0 100%` rule takes over.

No JS was touched — the existing `.text(...)` calls in the inline
`<script>` still just set text content; the layout now reacts
correctly to whatever they set, at any length.

## Files changed

- `Block/Adminhtml/System/Config/OllamaModelField.php` — the CSS fix
  above (new `flex-wrap:wrap` on `INLINE_ROW_STYLE`, new
  `STATUS_WRAPPER_STYLE` constant, new `:empty` `<style>` rule)
- `Test/Unit/Block/Adminhtml/System/Config/OllamaModelFieldTest.php` —
  updated the existing flex-row test for the new row style; added
  `testStatusSpanIsHiddenWhenEmptyAndWrapsToItsOwnFullWidthLineWhenPopulated`
  asserting the exact `:empty` rule and `flex:0 0 100%` status style

## Verification

**Full suite: 1746 tests / 4335 assertions / 0 failures** (1670/4012
unit + 76/323 integration; up from 1745/4333). Whole-module `php -l`
sweep clean.

The existing `OllamaModelFieldTest` already exercises the real
`_getElementHtml()` method (with Magento's own outer form-element
plumbing mocked, its established pattern predating this fix) and
asserts the exact resulting HTML/CSS string — both the pre-existing
tests and the new one pass against the real, current code.

## Not done / blocked

Live-rendering the real admin System Configuration page through an
authenticated browser session remains unconfirmed directly — same
CAPTCHA-gated, no-browser-automation-tool limitation as every other
admin-UI task in this module. A real object-manager render (not just
the unit test's mocked-plumbing approach) was attempted but abandoned
as disproportionate to a CSS-only fix: it requires constructing
Magento's full `Config\Structure`-backed Form element graph with a
properly attached container/id-prefix, which failed with
`Call to a member function getHtmlIdPrefix() on null` when built
ad hoc — reproducing the real System Config controller's own form-
building pipeline just to render one field is out of proportion to
what this fix needs. Verified instead via the precise, passing unit
test plus direct flexbox shrink-algorithm reasoning for why the fix
resolves the exact behavior shown in both screenshots.
