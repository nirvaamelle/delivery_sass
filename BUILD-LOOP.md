# Build Loop — the prompt

> Paste the block below as the `/loop` prompt. It runs one task per iteration, proves it, and
> stops. It is safe to run any number of times; it locates itself from `BUILD-STATE.md` every
> time and never assumes it remembers the previous iteration.
>
> **Invocation:** `/loop` with no interval (self-paced — each iteration takes as long as the task
> takes). An interval like `/loop 30m` fires on a clock and will interrupt work mid-task; don't
> use it here.

---

## The prompt

```
You are building the Construction ERP described in PHASE-PLAN.md, one task per iteration.

## Step 1 — Locate yourself

Read BUILD-STATE.md. It is the single source of truth for what is done. Do NOT re-read all of
PHASE-PLAN.md; read only the section for the CURRENT PHASE named in BUILD-STATE.md.

Pick the FIRST task whose status is `todo` or `failed`. That is your task this iteration. One
task. Do not start a second one, however small it looks.

If every task in the current phase is `done`, go to Step 5 (phase exit gate) instead.

## Step 2 — Build it, test first

Invoke the superpowers:test-driven-development skill and follow it.

Write the failing test before the implementation. For a gate or a control, the test that matters
is the one asserting it REJECTS — a passing happy path proves nothing about a gate.

Domain logic goes in app/Domain. Filament resources only call into it. This is the one
architectural rule from PLAN.md section 3 and it is not negotiable.

## Step 3 — Prove it. Evidence, not claims.

Invoke superpowers:verification-before-completion and follow it.

Run every check below and capture the ACTUAL OUTPUT. You may not mark a task `done` on the
strength of "it should work."

  1. php artisan test                          → must be green, 0 failures
  2. ./vendor/bin/pint --test                  → must be clean
  3. ./vendor/bin/phpstan analyse              → must be clean (once Larastan is installed)
  4. php artisan migrate:fresh --seed          → must complete without error
  5. storage/logs/laravel-*.log                → no new ERROR or CRITICAL since this iteration
                                                 started. Truncate the logs before you begin so
                                                 "new" is unambiguous. DAILY files since P6-06:
                                                 checking the old single laravel.log would pass
                                                 forever off a file nothing writes to.
  6. Browser console must be CLEAN. Run `npm run test:console` — a committed Playwright
     gate that boots the app itself and fails on any console error, console warning,
     pageerror, or HTTP >= 400. When a task adds a Filament screen, ADD IT to
     tests/Browser/console.spec.js in the same task; a gate that does not cover the new
     page is not covering the task. If the task touched no UI, write "no UI surface".

If ANY check fails: fix it and run the whole list again from the top. Do not proceed with a
known failure. Do not rationalise a warning as acceptable. Do not silence a log line to make a
check pass — fix the cause.

If you fail the same check 3 times in a row, stop fixing. Set the task to `blocked`, write what
you tried and the exact error into BUILD-STATE.md, and end the iteration. A human will look.

## Step 4 — Record it

Update BUILD-STATE.md:
  - task status → `done`, with the date
  - paste the actual test count and the pint/phpstan result under Evidence (e.g. "47 passed,
    0 failed"). Real numbers, copied from the output. Never invented.
  - append one line to the Log

Commit. Message format: `phase N: <task id> — <what it does>`. Do not push.

Then STOP. One task per iteration. Ending the iteration is correct behaviour, not quitting.

## Step 5 — Phase exit gate (only when every task in the phase is `done`)

Find the phase's Exit gate in PHASE-PLAN.md Part C. It is a behavioural statement, not a
checklist of code.

Write an end-to-end test that demonstrates the exit gate, and run it. For Phase 1 that means a
PR travelling all the way to an AP voucher, with a sole-source purchase escalating one level and
an expired-accreditation vendor being REJECTED from an RFQ. The rejections are the point.

Then run the full Step 3 check list one more time across the whole app, not just the last task.

If the exit gate passes: set the phase to `done` in BUILD-STATE.md, expand the NEXT phase's
tasks from PHASE-PLAN.md Part C into the task table (findings it must close, entry conditions,
deliverables), set it as CURRENT PHASE, and stop. Do not begin the new phase in the same
iteration.

If it fails: add the gap as a new `todo` task in the current phase and stop. The phase is not
done.

## Rules that apply to every iteration

- BUILD-STATE.md is the truth. If it disagrees with your memory, it wins.
- Never mark anything `done` you have not seen pass with your own eyes this iteration.
- Never skip a phase, never work two phases at once, never start Phase N+1 tasks because they
  seem easy.
- Blocked on a client decision from PHASE-PLAN.md Part D? Do NOT stall. Use the deck default,
  tag it in code with `// PLACEHOLDER: Part D item N`, add a line to DECISIONS-PENDING.md, and
  keep going. Thresholds are configuration — the client is expected to overwrite them.
- Money is DECIMAL(18,4). Never float, never integer cents.
- Encrypt sensitive columns at rest in the FIRST migration that creates them: vendor bank
  details, employee government numbers, salary rates. Not later.
- If PHASE-PLAN.md is wrong or contradicts the deck, fix the plan in the same commit and say so
  in the Log. The plan is not sacred; the deck is.
```

---

## Files the loop owns

| File | Purpose |
|---|---|
| `BUILD-STATE.md` | Where the build is. Task table, evidence, log. The loop's memory. |
| `DECISIONS-PENDING.md` | Placeholders used because a Part D decision is unanswered. |

## Before the first run

- `B2` (peso limits, Part D item 1) blocks the **Phase 1 exit gate**, not Phase 0. The loop will
  build Phase 1 against the deck's bands and flag them. Confirm the real numbers by week 2 or
  the approval-routing tests stay provisional.
- Part D item 14 (withholding tax) is the one that should be answered soonest. The loop will add
  the columns regardless — but the *rates* change every amount that moves, and getting them
  wrong is expensive after the ledger has rows.
