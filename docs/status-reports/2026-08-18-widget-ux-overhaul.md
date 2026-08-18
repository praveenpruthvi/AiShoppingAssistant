# STATUS REPORT — Widget UI/UX overhaul

Six-part chat-widget overhaul: restyled Luma/Hyva templates, two new
admin-configurable color settings, real product images in cards,
top-left resize + minimize/maximize, and a fix for the floating toggle
button — which turned out to be a genuine, previously undiagnosed CSS
cascade bug, not a JS wiring problem. Two unrelated pre-existing issues
were also found and fixed along the way: a stale DI-compilation state
that silently broke 17 tests, and a much larger catalog-wide image gap
than a prior task had reported.

## Files created/changed

**New:**
- `Api/Config/AppearanceConfigInterface.php`, `Model/Config/
  AppearanceConfig.php` — the two new color settings' DTO/interface.

**Modified (production):**
- `Model/Config/Path.php`, `Api/Config/ConfigurationReaderInterface.php`,
  `Model/Config/ConfigurationReader.php` — `readAppearance()` +
  strict-hex `readColor()` validation.
- `etc/adminhtml/system.xml` — new "Appearance" group, 3 fields.
- `Block/Frontend/ChatWidget.php` — 3 color getters +
  `getColorCustomPropertiesStyle()`.
- `Model/Revalidation/RevalidatedProduct.php` — new optional trailing
  `?string $imageUrl = null`.
- `Model/Revalidation/LiveRevalidationService.php` — new
  `resolveImageUrl()` via `Magento\Catalog\Block\Product\ImageFactory`.
- `Model/Chat/ChatResponseSerializer.php` — `serializeProduct()` gained
  `image_url`.
- `view/frontend/templates/chat/{widget,widget-hyva}.phtml` — styling
  refresh, color CSS custom properties, resize handle, minimize button,
  product-image markup.
- `view/frontend/web/js/chat-widget-core.js` — `normalizeProduct()`
  gained `imageUrl`.
- `view/frontend/web/js/chat-widget-luma.js`, `chat-widget-hyva.js` —
  class-based open/minimize state, custom top-left resize drag, image
  rendering, `sessionStorage` persistence, Hyva auto-scroll.

**Modified (tests):**
- `Test/Unit/Model/Revalidation/RevalidatedProductTest.php` (+3)
- `Test/Unit/Model/Revalidation/LiveRevalidationServiceTest.php` (+2)
- `Test/Unit/Model/Config/ConfigurationReaderTest.php` (+3)
- `Test/Unit/Block/Frontend/ChatWidgetTest.php` (+3)
- `Test/Unit/Model/Chat/ChatResponseSerializerTest.php` (assertions
  extended, no new test)

**Tests:** 11 net new (1271 → 1282 unit, 3093 → 3125 assertions), 0
failures. JS verified with real headless-Chrome sessions (no JS test
framework exists for the widget — Task 11's standing gap, unchanged).

## Conventions followed

`RevalidatedProduct::imageUrl` is a nullable, defaulted *trailing*
constructor parameter — this module's established pattern for
extending a DTO without breaking any of its (now 19) existing call
sites, most recently used for `StoredConversationMessage`'s own
response-payload field in Task 20. `AppearanceConfigInterface`/
`AppearanceConfig` mirror `GeneralConfigInterface`/`GeneralConfig`'s own
split exactly. Every design decision was verified live before being
called done — every finding below was caught by an actual browser
session or a real command's output, not by static review alone.

## Deviations from existing conventions

None.

## Part 1 — Styling refresh

Both templates got a subtle gradient header/toggle (`linear-gradient`
with CSS `color-mix()` for a darker second stop, so it looks right for
*any* admin-configured color without a second admin field), a two-layer
box-shadow on the panel, and tighter spacing/typography on bubbles and
product cards. Hyva keeps its Tailwind-utility-class paradigm for
everything else; only the color-dependent rules live in a small
co-located `<style>` block, the same pattern Luma's template already
used, rather than fighting Tailwind's static utility system for
something it structurally can't express.

## Part 2 — Configurable window/header color

A new "Appearance" admin group (`system.xml`) with a `primary_color`
field. `ConfigurationReader::readColor()` accepts only strict
`#rgb`/`#rrggbb` hex — anything else, including an attempted CSS
injection typed into the field, is dropped and the widget silently
falls back to the hard-coded default, never emitted as raw CSS.
`ChatWidget::getColorCustomPropertiesStyle()` builds the `style="--
aavirbhava-primary-color:...;..."` attribute set on each template's
root element; every color-dependent rule reads `var(--aavirbhava-
primary-color, #1979c3)`.

**A real bug found while verifying this live:** the toggle button's
`:hover` rule only set `transform`/`box-shadow`, no longer restating
`background`. Luma's own global `button:hover` reset (`styles-m.css`)
has higher CSS specificity (element + pseudo-class) than a bare class
selector, so hovering the toggle silently fell back to Luma's flat gray
button color instead of the configured gradient — caught by comparing
hovered vs. non-hovered computed styles in a live browser (the
non-hovered state was already correct), not by reading the CSS.  Fixed
by restating the background explicitly inside the `:hover` rule on
both themes.

## Part 6 — Configurable message colors

Two more fields, `message_bubble_color`/`message_text_color`,
deliberately distinct from the window/header color. Same
custom-property mechanism, applied only to the assistant's own chat
bubbles (`.aavirbhava-chat-bubble--assistant`) — the user's own bubble
stays a fixed light-blue tint on both themes, matching the pre-existing
design.

## Part 3 — Product images

`RevalidatedProduct` gained a live-resolved `imageUrl`, following the
exact discipline already established for price/URL: never LLM-sourced,
always freshly resolved from the real Magento product.

**The first implementation was wrong, and a real bug.** It used
`Magento\Catalog\Helper\Image::init()->getUrl()` — live-verified via a
real chat round-trip to return a broken placeholder URL
(`.../placeholder/.jpg`, an empty filename) for products that
genuinely have a real image, despite Luma's own PDP/category pages
rendering those same products correctly. Root-caused to `Helper\Image`
performing an eager, synchronous file-existence check and resize at
URL-build time (the reason it's deprecated) that behaves differently
outside a full block/layout render than Magento's modern,
non-deprecated `Magento\Catalog\Block\Product\ImageFactory` — the same
lazy URL-building mechanism Luma's own templates use via `$block->
getImage()`, which never touches the filesystem at build time and lets
the real resize happen lazily on first HTTP request to the URL, exactly
like every other product image on the store. Switching to `ImageFactory`
immediately produced correct URLs, live-confirmed via `naturalWidth`
reading actual decoded pixel dimensions (135, the real
`product_small_image` size), not just a non-empty `src` or a 200
status. `product_small_image` (135×135, Luma's own conversion) was
chosen as sized between the 75×75 thumbnail (too small next to a
name/price) and the 240×300 category grid image (would dominate a
narrow chat bubble).

## A much larger pre-existing catalog gap, found and fixed as a byproduct

Live-testing the image feature surfaced that most product images across
the *entire catalog* — not just the chat widget, including PDP pages
that don't touch this module's code at all — were rendering Magento's
placeholder. Root-caused to the earlier "install sample data" task's
manual recovery (`cp -rn` from the vendor package into `pub/media/
catalog/product/`) never replicating Magento's own import-time
collision-dedup behavior: 751 of the catalog's 795 distinct
DB-referenced image filenames use a `_1`/`_2`/etc. suffix (e.g.
`mp09-blue_main_1.jpg`) that the vendor sample-data-media package never
ships under that exact name — only the unsuffixed base file
(`mp09-blue_main.jpg`) exists there, with Magento's own installer
normally creating the suffixed copies at import time to avoid literal
filename collisions between different product/gallery entries that
happen to share a source image.

That prior task's own live verification ("~70 of 795 images missing,
affecting ~137 `WSH*`-prefixed products") undercounted the true gap by
an order of magnitude and misattributed it to the vendor package itself
being incomplete. This is corrected here: every one of the 751 affected
filenames' unsuffixed base version was already present on disk, so the
fix was a safe, additive copy of each base file to its missing suffixed
sibling path(s) — 751 files — followed by `catalog:images:resize`
(795/795 succeeded, zero "original image not found" warnings, confirmed
by inspecting the full command output) and a direct database audit
confirming all 795 distinct referenced images now exist. The
previously-flagged `WSH01` product was re-checked live and now renders
its real photo on its own PDP.

## Part 4 — Resize handle relocation + minimize/maximize

Native CSS `resize` only supports a bottom-right drag handle, so a
custom top-left handle was built using the Pointer Events API on both
themes (Alpine's `@pointermove.window`/`@pointerup.window` modifiers on
Hyva, since dragging can leave the handle element under the cursor).
Because the panel is anchored via `right`/`bottom` (not `left`/`top`)
on both themes, growing `width`/`height` from a top-left-positioned
handle naturally expands the panel upward and leftward while the
bottom-right corner never moves — live-confirmed via a before/after
bounding-box comparison showing `right`/`bottom` unchanged while
`left`/`top`/`width`/`height` all moved as expected.

A minimize button next to close collapses the panel to just its header
bar via a `--minimized` class. **A second real bug was found and fixed
here too:** the base panel rule's `min-height: 360px` isn't overridden
by `height: auto` on the minimized class — a `min-height` floor always
wins over `height: auto`'s computed value — so the first minimized-state
implementation still rendered a tall, mostly-empty box below the
header. Caught via a live screenshot (not just a DOM-state assertion,
which would have shown "correct" `display:none` on the log/form while
missing the container itself still being tall), fixed by also setting
`min-height: 0 !important` on the minimized class on both themes.
Open/minimized state persists across the same session via
`sessionStorage`, wrapped in try/catch so storage unavailability
degrades to "state doesn't survive a reload," never a broken widget.

## Part 5 — The floating-button bug

Confirmed the toggle's click handler was always correctly flipping the
panel's `hidden` DOM property (verified via `page.evaluate()` reading
the live property value across clicks) — the bug was entirely visual.
`widget.phtml`'s own `.aavirbhava-chat-panel { display: flex; }` rule is
author-origin CSS, which unconditionally wins the cascade over the
browser's user-agent-origin `[hidden] { display: none }` default
*regardless of selector specificity* — so the panel rendered visually
open on every single page load no matter what was clicked, confirmed by
two before/after screenshots that were pixel-identical. Fixed by
switching the show/hide mechanism from the `hidden` attribute to a
`.aavirbhava-chat-panel--open` class (`display: none` by default,
`display: flex` only with the class), toggled via `classList` instead
of the `.hidden` property.

Hyva's existing panel was never affected by this bug: `x-show` toggles
the element's *inline* `style="display:none"`, and an inline style
always outranks any class-selector rule — confirmed by inspecting its
`x-show="open"` + Tailwind `flex` utility class before ruling it out,
rather than assuming.

## Optional items

**Added:** auto-scroll for Hyva (Luma already had it; Alpine reactivity
doesn't auto-scroll on its own, so a `$nextTick`-based scroll call was
added after every message push); visible `:focus-visible` outlines on
Luma's interactive elements.

**Already present, no work needed:** typing/loading indicator (both
themes; given a light CSS animated-dots polish on Luma's),
Enter-to-send (native form submission), ESC-to-close (both themes
already had it, though Luma's check needed updating from `!panel.hidden`
to a new `isOpen` variable as part of the class-based-toggle fix).

**Skipped, per the task's own scope guidance:** an unread-message
indicator on the floating button when minimized — this architecture has
no server-push/unprompted-new-message mechanism (every message is a
direct request/response the customer themselves triggered), so there is
never a message the customer hasn't already seen arrive while
minimized; the indicator's premise doesn't apply here.

## A pre-existing, unrelated 17-test environment failure found and fixed

The full unit suite initially showed 17 errors, all in
`AddToCartToolTest.php` (`MethodCannotBeConfiguredException` mocking
`Magento\Quote\Api\Data\ProductOptionExtensionInterface::
setConfigurableItemOptions()`) — confirmed unrelated to any file this
task touched. Root-caused to a stale/incomplete DI-compilation state
left over from the earlier "install sample data" ad hoc task:
`generated/code/Magento/Quote/Api/Data/ProductOptionExtensionInterface.php`
(an extension-attribute interface Magento generates on demand) was
simply missing, and the unit-test bootstrap's plain `app/autoload.php`
— unlike a full `Bootstrap::create()` app context — doesn't wire up the
runtime auto-generation autoloader that would otherwise create it
lazily. Fixed by re-running `bin/magento setup:di:compile`, which
regenerated the missing interface; the full suite was confirmed green
both before this task's own code changes (isolating the 17 errors as
pre-existing) and after (0 failures either way once compiled).

## Live verification

Real headless-Chrome sessions against the real storefront, real
local-model responses, no scripting at any layer beyond driving the
browser:

1. The floating button genuinely opens and closes the panel
   (`display: none` → `flex` → `none` across successive clicks, read
   from `getComputedStyle`, not just DOM-attribute state).
2. Changing the two new color settings (`#8e44ad` primary, `#fff3cd`/
   `#7a5b00` message bubble) visibly changed the rendered header
   gradient and assistant bubble colors, in both the default and
   hovered states.
3. The resize handle sits at the panel's top-left corner; dragging it
   grows the panel while the bottom-right corner's screen position
   stays exactly fixed (`right`/`bottom` unchanged, `left`/`top`
   decreased, `width`/`height` increased by the drag distance).
4. Minimize collapses the panel to a ~35px header-only bar; maximize
   restores the full 602px panel — confirmed via bounding-box
   measurements.
5. A real "show me yoga pants" query returned 8 product cards, each
   with a real, resized (135×135) product photo — confirmed via
   `naturalWidth` reading actual decoded pixel dimensions.
6. Reloading the page after a live conversation restored the exact same
   panel-open state and the full 8-product-card transcript with images
   intact, via the existing Task 20 history-restore path picking up the
   new `image_url` field with zero additional wiring.

## Container verification

`php -l` on every changed PHP file and both `.phtml` templates,
`setup:upgrade`, `setup:di:compile`, `cache:flush` all clean.

## Test results

1271 → 1282 unit tests (+11), 3093 → 3125 assertions, 0 failures — run
both before this task's changes (to confirm the 17 pre-existing
`AddToCartToolTest` errors predated this task) and after (0 failures
either way once the DI-compile state was fixed).

## Known gaps / TODOs left for later tasks

None newly introduced. The Hyva template still cannot be live-verified
in this environment (Task 11's original gap, unchanged) — its
resize/minimize/color/image changes were built to mirror Luma's
live-verified behavior and to the same Alpine/Tailwind conventions, but
are unverified against a real Hyva theme. No JS unit-test framework
exists for the widget's own JS (Task 11's original gap, unchanged).

## Skill files updated

`references/progress-log.md` — status rows 9 and 12 updated; header
summary updated; a full Task 21 history entry added.

## Not done / blocked

Nothing blocked. All six required parts, plus two of the six optional
items, were completed and live-verified in this session — along with
two unrelated pre-existing issues (the DI-compile gap and the
catalog-wide image gap) discovered and fixed along the way.
