# Claude Code Task Prompt Template

Copy this shape for every new task. Fill in the bracketed parts. Keep each
task narrowly scoped to one adapter / one service / one pipeline slice —
don't bundle adjacent unbuilt pieces even if the architecture doc lists
them nearby.

```
TASK: [one-line description of exactly this task's scope]

ENVIRONMENT: Magento runs locally via docker-magento
(https://github.com/markshust/docker-magento). Use its bin/* wrappers
(bin/cli, bin/magento) for all verification. Don't assume a bare-metal
install.

CONTEXT: [1-3 sentences: what already exists that this task builds on,
pulled from progress-log.md — name real files/classes when known]

STEP 1 — Inspect before writing:
Read and summarize (don't paste full contents, just note signatures/patterns):
- [specific interface(s)/DTO(s) relevant to this task]
- [one existing analogous implementation to mirror conventions from —
  config reading, DI wiring, error handling, logging, test structure]
- [existing exception taxonomy / policy classes this task's errors must
  map into]
- [existing config groups in system.xml, if this task needs config]

STEP 2 — Implement:
[Precise description of what to build, following conventions found in
Step 1.]

Requirements:
- [functional requirement 1]
- [functional requirement 2]
- [error/exception mapping requirement — map into EXISTING taxonomy, don't
  invent a parallel hierarchy]
- [test requirement — mirror existing test style/location]
- Do NOT build: [explicitly list adjacent pieces this task should NOT
  touch — this is the single most important line to prevent scope creep]

STEP 3 — Verify inside the running container:
- bin/cli php -l on all changed files
- bin/magento setup:upgrade / setup:di:compile / cache:flush as needed
- Run the relevant test suite(s), report before/after counts
- At least one live bootstrap/functional check proving the wiring works
  end-to-end (not just unit-testable in isolation) — e.g. instantiate the
  service, exercise it against a fake/mock dependency, confirm the
  expected code path is/isn't reached

STEP 4 — Write the report to a file, then print it:

Write the STATUS REPORT (structure below) to a new markdown file at
docs/status-reports/<YYYY-MM-DD>-<short-task-slug>.md (create the
directory if it doesn't exist yet; check first whether the project
already has a status-reports convention/location from a prior task and
reuse it instead of inventing a second one). The filename must be unique
— if a file for today's date + slug already exists, append -2, -3, etc.
rather than overwrite it. After writing the file, also print the full
report in the chat response as before — don't only write it silently.

## STATUS REPORT
**Files created/changed:** (list with one-line purpose each)
**Conventions followed:** (which existing pattern you mirrored, and where)
**Deviations from existing conventions:** (what and why — include any
  verification you did before making a breaking change, e.g. "checked
  zero other callers before changing signature X")
**[Task-specific design section, e.g. "Scope classifier design" /
  "Exception mapping" / "Response contract shape"]:** (the key design
  decisions specific to this task and why you drew the line where you did)
**Container verification:** (commands run, what confirmed correct wiring)
**Test results:** (new test counts, full suite before/after, any failures)
**Known gaps / TODOs left for later tasks:** (explicitly confirm which
  adjacent pieces from the "Do NOT build" list were correctly NOT built)
**Not done / blocked:** (anything incomplete and why)
```

## Notes on filling this in well

- **CONTEXT and Step 1 file names** should come from `progress-log.md`'s
  task history, not be guessed — if a prior task's status report named
  specific files, point the next task at exactly those files.
- **The "Do NOT build" line in Step 2** is the highest-leverage line in
  the whole template. This project's failure mode isn't sloppy code, it's
  scope creep into adjacent unbuilt architecture pieces. Every task should
  end with a short, explicit exclusion list.
- **Task-specific design sections** should ask Claude Code to explain a
  decision that has downstream consequences — e.g. for a classifier task,
  ask why the line between in/out of scope was drawn where it was; for a
  contract task, ask for the exact JSON shape produced. This is what
  makes the pasted-back report reviewable instead of just a pass/fail.
- Don't skip the live/functional verification step even when unit tests
  pass — this project has repeatedly caught real wiring gaps (e.g. a
  provider registered in DI but not actually reachable through the admin
  dropdown) only by checking inside the running container.
- **Status reports are files, not just chat output.** Every task prompt's
  Step 4 must have Claude Code write the report to
  `docs/status-reports/<date>-<slug>.md` with a unique filename, so the
  user can upload the file here instead of copy-pasting. When folding a
  report back into `progress-log.md`, note the report's file path in the
  task history entry if the user mentions it, so the two stay
  cross-referenced.
