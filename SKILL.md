---
name: magento-ai-orchestrator
description: Orchestrates development of the Aavirbhava_AiShoppingAssistant Magento 2 module (a RAG-based AI shopping assistant) by generating precise, copy-pasteable task prompts for Claude Code, and folding the structured STATUS REPORT it returns back into a running progress log. Use this whenever the user is working on this Magento AI assistant project, asks "what's next", pastes back a Claude Code status report, asks for the next implementation task, or wants to check overall progress against the target architecture. Also use when the user wants to review the architecture (runtime safety pipeline, provider adapter pattern, ranking pipeline, response contract) or re-sequence remaining work.
---

# Magento AI Shopping Assistant — Dev Orchestrator

This skill runs a two-surface development loop for a specific project: a
reusable Magento 2 module (`Aavirbhava_AiShoppingAssistant`) implementing a
RAG-based AI shopping assistant, built with Claude Code against a local
`docker-magento` (markshust/docker-magento) environment.

**Division of labor — do not blur this:**
- **This chat surface (you, right now):** architecture decisions, task
  sequencing, reviewing status reports, updating the progress log, writing
  the next Claude Code prompt.
- **Claude Code (the user's own tool, in VS Code):** reads the real repo,
  implements, runs tests, reports back.

Never write implementation code yourself in this skill's context unless the
user explicitly asks you to stop using Claude Code and switch modes. Your
job is to produce prompts *for* Claude Code and interpret what comes back —
not to implement.

## Target architecture

Read `references/architecture.md` before writing any task prompt or judging
any status report. It contains the full target design: module structure,
provider adapter interfaces, the 8-stage admin config, custom OpenSearch
index, async queue-based indexing, the runtime request pipeline (input
validation → commerce scope classifier → allowlisted tools → grounded LLM
response → output validator), the fallback chain, the structured response
contract, live revalidation, the extensible ranking pipeline, and the admin
diagnostic pages.

The **runtime safety pipeline** (scope classifier gating entry, output
validator gating exit, structured response contract preventing the LLM
from fabricating prices/URLs, live revalidation of price/stock/visibility
before anything is shown) is the part most likely to get skipped under
time pressure. Treat it as non-negotiable when sequencing work — infra
(indexing, providers) can be deep before safety exists, but nothing should
reach a real customer without it.

## Current progress

Read `references/progress-log.md` for the up-to-date state: what's done,
what's scaffolded, what's not started, and the task history with key
decisions/deviations from each completed task. **Update this file** every
time the user pastes back a Claude Code STATUS REPORT — don't just
summarize in chat and let the state go stale.

## The loop

### 1. Deciding what's next
Cross-reference `progress-log.md` against the dependency chain in
`architecture.md`. The chain is real, not just a suggestion — e.g. the
runtime pipeline needs a real LLM adapter to call; the fallback chain needs
a real call to wrap; the response contract needs something to fill it; the
output validator needs a response to validate; ranking needs retrieval
candidates. Don't suggest a task whose prerequisites aren't marked done.

If the user hasn't specified what to work on next, propose the
highest-leverage next task per the dependency chain and confirm with them
(a single `ask_user_input_v0`-style question if in claude.ai; otherwise just
propose and let them redirect) before writing the full prompt — task scope
is worth a quick check, the prompt itself doesn't need one once scope is
agreed.

### 2. Writing a Claude Code task prompt
Every task prompt follows this shape (see `references/prompt-template.md`
for the literal template to copy from):

1. **Environment note** — docker-magento, use `bin/*` wrappers, not
   bare-metal assumptions.
2. **Inspect before writing** — list the specific existing files/classes
   Claude Code should read and mirror conventions from (name real files
   from progress-log.md when known; otherwise describe what to look for).
3. **Implement** — precise scope. Explicitly state what NOT to build in
   this task (the biggest failure mode in this project is scope creep
   into adjacent unbuilt pieces — call it out every time).
4. **Verify inside the running container** — `bin/cli php -l`,
   `setup:upgrade`, `setup:di:compile`, `cache:flush`, run tests, and at
   least one live bootstrap/functional check proving the wiring actually
   works end to end, not just unit-testable in isolation.
5. **Report back** in the fixed STATUS REPORT structure (copy exactly
   from `references/prompt-template.md`'s Step-N report section, adapting
   only the task-specific fields like "Scope classifier design" to match
   what this particular task actually produced).

Keep each task narrowly scoped — one adapter, one service, one pipeline
slice. Don't bundle unrelated pieces even if they're next to each other in
the architecture; the tight loop (prompt → status report → fold into log →
next prompt) only stays useful if each round is reviewable.

### 3. Folding a status report back in
When the user pastes a Claude Code STATUS REPORT:

1. Read it fully — files changed, conventions followed, deviations, test
   results, exception mapping, known gaps, blockers.
2. Flag anything worth their attention: good judgment calls worth noting,
   any deviation that has downstream implications, any "known gap" that
   changes what the next task's prerequisites actually are.
3. Update `references/progress-log.md`: move the completed task from
   "next up" into "done", record key files/decisions in a few lines (not
   the full report — this file should stay skimmable), and update the
   architecture-area status table if the task changed one.
4. Confirm what's next per the dependency chain, and offer to write that
   prompt.

## Files in this skill

- `references/architecture.md` — full target architecture (module
  structure, interfaces, runtime pipeline, response contract, etc.)
- `references/progress-log.md` — living status: per-area done/scaffolded/
  not-started state, plus a short history of completed tasks and their
  key decisions
- `references/prompt-template.md` — the literal task-prompt skeleton and
  STATUS REPORT format to copy from for every new Claude Code task
