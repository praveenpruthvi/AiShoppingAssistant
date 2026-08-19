# STATUS REPORT — Follow-up-chip voice fix (customer, not assistant)

`follow_up_questions` chips were rendering in the assistant's own voice
("Would you like to add this to your cart?"), but the storefront widget
sends a clicked chip's exact text back as the *customer's own next
message* — nothing in the prompt had ever told the model which voice to
use. Fixed with a prompt-only change: `ResponseContractFormatter` now
instructs the model to write these in the customer's own voice, and
`LlmResponseSchema` gained a matching schema-level `description` for a
second, provider-native reinforcement. No frontend change was needed —
confirmed by reading the widget's click handlers, not assumed.

## Files created/changed

**Modified (production):**
- `Model/Chat/ResponseContractFormatter.php` — new paragraph
  instructing customer-voice `follow_up_questions`; every existing
  paragraph kept verbatim.
- `Model/Chat/Response/LlmResponseSchema.php` — new `description` on
  the `follow_up_questions` property, the first this schema has ever
  had.

**Modified (tests):** `Test/Unit/Model/Chat/
ResponseContractFormatterTest.php` (+1), `Test/Unit/Model/Chat/
Response/LlmResponseSchemaTest.php` (+1).

**Not touched:** no frontend files — see Part A.

**Tests:** 2 net new (1391 → 1393 unit, 3357 → 3363 assertions), 0
failures.

## Part A — where this is generated, and whether the widget does anything voice-specific

`ResponseContractFormatter` is the only place instructing the model on
this field's content. Before this task, the instruction was exactly
`"follow_up_questions" (array of strings)` — no guidance on phrasing or
voice at all. `LlmResponseSchema` mirrored that emptiness at the schema
level too.

On the frontend, both themes' click handlers were read directly:
`chat-widget-luma.js`'s `submitMessage(question)` and
`chat-widget-hyva.js`'s `askFollowUp(question)`. Neither does anything
voice-specific — a clicked chip's exact text is sent back as the next
real customer message through the identical code path a typed message
uses, with no separate "this came from a suggestion chip" signal
reaching the backend at all. **Confirmed no frontend change was
needed**, exactly as the task anticipated, rather than assumed.

## The fix

A new paragraph in `ResponseContractFormatter::INSTRUCTIONS`:

> Write every follow_up_questions entry in the CUSTOMER's own voice,
> never the assistant's. Each one becomes a clickable suggestion that
> gets sent back to you verbatim as though the customer had typed it
> themselves, so it must be a short, natural thing the customer might
> actually say or ask next — e.g. "add the Tiberius Gym Tank to my
> cart", "show me other tank tops under $20", "what's it made of", "do
> you have this in blue". Never phrase one as a question addressed TO
> the customer, like "Would you like to add this to your cart?" or
> "Which of these interests you most?" — a suggestion in the
> assistant's voice puts the assistant's own words in the customer's
> mouth and confuses the next turn.

`LlmResponseSchema`'s `follow_up_questions` property also gained a
matching `description` — added specifically because a real
OpenAI-compatible provider's structured-output mode does read and follow
JSON Schema `description` text, giving this one instruction a second,
provider-native reinforcement alongside the plain-language paragraph.
Not retrofitted onto every other schema property — only this one field
had a live-reproduced voice bug worth the extra guidance.

This is a **prompt-only fix**: the response contract's shape,
`LlmResponseParser`, and `OutputValidator` are all unchanged.

## Live verification

Reproduced the reported bug first, then confirmed the fix:

**"show me gym tank tops"** — real chips before the fix would have read
like the assistant's own in-message question ("Which of these catches
your eye? Want to see details, compare options, or add something to your
cart?"). After the fix, the real chips were:

```
["add MT10 to my cart", "add MT11 to my cart", "add MT08 to my cart", ...]
```

Clicking through one for real (sending `"add MT10 to my cart"` as the
actual next message) produced *"The Tiberius Gym Tank (MT10) was added
to your cart."* with a real `add_to_cart` action and a real product card
— not the assistant getting confused by its own words. The *next* turn's
chips (`"add the Sparta Gym Tank to my cart"`, `"what is this made of"`)
were customer-voice too.

**"show me running shoes"**, repeated across two separate real runs —
chips like `"see the Erika Running Short"`, `"compare the Erika and
Sybil Running Shorts"`, `"is the Apollo Running Short in stock"` —
consistently customer-voice.

`system.log` checked for the Task 24 debug-log-leakage class of bug
across every live query this task ran — none found.

## Honestly reported: the fix does not reach 100% for every query shape

A purely informational query, **"what are yoga pants made of"**, produced
assistant-voice chips (`"Are you looking for breathable material for warm
weather..."`, `"Would you like to see which of these options is
currently in stock?"`) in **3 out of 3** repeated real attempts.

This is the same local-model reliability gap this module has documented
in every prior prompt-only fix (Tasks 18, 23, 25, 27) — a genuine,
unresolved limitation, not something this task claims to have solved
universally. The fix is real and live-confirmed for product-search and
cart-action queries — the two shapes the reported bug's own example
matched — but is not a guarantee across every conversational shape.

## Verification

Full suite 1391 → 1393 unit tests (+2), 3357 → 3363 assertions, 0
failures, run before this task's changes and again after. `php -l` on
every changed file, `setup:di:compile`, `cache:flush` clean (no
schema/DI-wiring change, no console command, `setup:upgrade` not
needed).

## Known gaps / TODOs left for later tasks

- The informational-query voice gap above is real and unresolved by
  this task — a later task with more live-testing budget could
  investigate whether a more specific instruction, a few-shot example,
  or a stronger provider (per this module's own standing, not-yet-
  executed "switch primary provider" option) closes it.
- No structural enforcement exists for follow_up_questions voice —
  unlike `OutputValidator`'s fabricated_sku/price/url checks, there is
  no code-level fallback here. An assistant-voice chip, if the model
  still writes one, still renders and still sends verbatim on click,
  exactly as before this task — just less often.

## Skill files updated

`references/progress-log.md` — status rows 8 and 12 updated; header
summary updated; a full Task 28 history entry added.

## Not done / blocked

Nothing blocked.
