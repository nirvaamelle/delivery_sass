# Build State

> The loop's memory. `BUILD-LOOP.md` reads this first, every iteration.
> **This file is the truth.** If it disagrees with anything else, it wins.
> Hand-edit freely — the loop will pick up whatever it finds here.

**CURRENT PHASE:** Phase 6 — Hardening and ops
**Started:** 2026-09-11 (Phase 1 opened 2026-09-10, closed 2026-09-11)
**Last iteration:** 2026-09-14 · iteration 83 · first staging deploy — live on shared hosting, and what that does not yet satisfy

---

## Environment

Laragon's binaries are **not on PATH** for non-interactive shells. Every iteration that runs
`php`, `composer`, `artisan` or `mysql` must export this first:

```sh
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
```

Shell state does not persist between tool calls — re-export in **each** call, not once.

| Tool | Version | Verified |
|---|---|---|
| PHP | 8.3.30 (ZTS, VC++ 2019 x64) | 2026-09-10 — satisfies PLAN.md §2 (PHP 8.3+) |
| Composer | 2.9.4 | 2026-09-10 |
| MySQL | 8.4.3 | 2026-09-10 |
| Node / npm | 24.18.0 / 11.16.0 | 2026-09-10 — on PATH already |

Extensions present: `bcmath` `gd` `intl` `mbstring` `openssl` `pdo_mysql` `zip`.
`bcmath` matters — it is what keeps `DECIMAL(18,4)` money arithmetic exact.

---

## Phase status

| Phase | Weeks | Status |
|---|---|---|
| 0 — Foundation | 1 | **exit gate green locally**; P0-16 blocked on a server |
| 1 — Procurement chain | 2–4 | **exit gate green locally**; staging clause + B2 outstanding |
| 2 — Acquisition to cash | 5–6 | **exit gate green locally**; staging clause outstanding |
| 3 — HRIS and payroll | 7–9 | **exit gate green locally**; staging clause + B3 outstanding |
| 4 — OPEX and the ledger | 10–11 | **exit gate green locally**; staging clause + Part D 11/13 outstanding |
| 5 — Close-out and reporting | 12–13 | **exit gate green locally**; staging clause + B4 outstanding |
| **6 — Hardening and ops** | 14 | **current — exit gate green locally for clause 1's walk-through and clause 2; clause 4 switched off for now; cannot close**: clause 1 "on staging" (Part D item 12) and clause 3 (B4) outstanding |

Tasks for a phase are expanded from `PHASE-PLAN.md` Part C **when that phase opens**, not before.

---

## Phase 0 — tasks

Status: `todo` · `doing` · `done` · `failed` · `blocked`

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P0-01 | Correct `PLAN.md` §1 chain-span table — all four chains run phases 1→4 | **F1** | **done** 2026-09-10 | Table corrected; per-chain phase-1/phase-4 evidence added from slide 2. Docs only — no app exists yet, so no test surface. |
| P0-02 | `laravel new` — **Laravel 13.31 + Filament 4.13**, MySQL on Laragon, app boots | | **done** 2026-09-10 | 2 tests / 2 passed / 2 assertions · pint passed · migrate:fresh --seed clean · laravel.log empty · **console 0 errors 0 warnings**, 12/12 requests 200, login → Dashboard verified |
| P0-03 | Test toolchain: **Pest 4.7.8**, Pint, **Larastan 3.11 / PHPStan level 5**, and `npm run test:console` — a committed Playwright console gate | | **done** 2026-09-10 | pest 2 passed / 2 assertions · pint passed · phpstan 0 errors · console gate 2 passed |
| P0-04 | spatie: **permission 8.3.0**, **activitylog 4.12.3**, **medialibrary 11.23.7** installed and wired on User | | **done** 2026-09-10 | pest 8 passed / 11 assertions · pint passed · phpstan 0 errors · console gate 2 passed |
| P0-05 | Core models: `organizations`, `projects` (phase enum, all four), `cost_codes` (WBS tree), `budgets`, `budget_lines` | F1 | **done** 2026-09-10 | pest 21 passed / 37 assertions · pint passed · phpstan 0 errors · 13 migrations clean · console gate 2 passed. 13 new tests, 8 of them rejections. |
| P0-06 | `document_sequences` + **Numbering service** — gapless per-type per-year, locked counter row inside the insert transaction. Never `MAX(id)+1` | | **done** 2026-09-10 | pest 30 passed / 47 assertions · pint passed · phpstan 0 errors · 14 migrations clean · console gate 2 passed. 9 tests: FOR UPDATE asserted from the query log, rollback proven not to burn a number. |
| P0-07 | `document_links` — the handoff spine. Predecessor/successor edges between every document | | **done** 2026-09-10 | pest 38 passed / 59 assertions · pint passed · phpstan 0 errors · 15 migrations clean · console gate 2 passed. 8 tests, 3 of them rejections including cycle closure. |
| P0-08 | `cutoff_calendars` with `cutoff_type` (`billing` monthly · `payroll` semi-monthly · `opex` day-26) + per-project override | **F3** | **done** 2026-09-10 | pest 46 passed / 69 assertions · pint passed · phpstan 0 errors · 16 migrations clean · console gate 2 passed. 8 tests, 4 of them rejections. |
| P0-09 | Withholding tax foundation: rate config, a reusable schema macro for withholding columns, convention documented so every money-bearing document adopts it | **F9** | **done** 2026-09-10 | pest 57 passed / 91 assertions · pint passed · phpstan 0 errors · 16 migrations clean · console gate 2 passed. 11 tests, 3 of them rejections. No UI surface. |
| P0-10 | **Gates service** — declarative preconditions per document, enforced service-side on every transition | | **done** 2026-09-10 | pest 64 passed / 101 assertions · pint passed · phpstan 0 errors · 16 migrations clean · console gate 2 passed. 7 tests, 3 of them rejections. No UI surface. |
| P0-11 | Project-level gate: no PR against a project without a signed contract **and** an opened budget | **F14** | **done** 2026-09-10 | pest 72 passed / 112 assertions · pint passed · phpstan 0 errors · 17 migrations clean · console gate 2 passed. 8 tests, 6 of them rejections. No UI surface. |
| P0-12 | `approval_matrix` + **Approvals service** — four tiers, per-tier required document sets, sole-source escalation one level above | | **done** 2026-09-10 | pest 84 passed / 136 assertions · pint passed · phpstan 0 errors · 19 migrations clean · 12 matrix rows seeded · console gate 2 passed. 12 tests, 4 of them rejections. No UI surface. |
| P0-13 | `project_cost_ledger` + **Posting service** — the only code permitted to write the ledger. Every posting carries project code, cost code, source document type and id | | **done** 2026-09-10 | pest 97 passed / 160 assertions · pint passed · phpstan 0 errors · 20 migrations clean · 2 immutability triggers live · console gate 2 passed. 13 tests, 7 of them rejections. No UI surface. |
| P0-14 | Filament: approval-matrix admin screen | | **done** 2026-09-10 | pest 104 passed / 184 assertions · pint passed · phpstan 0 errors · 20 migrations clean · **console gate 4 passed** (2 new pages added) |
| P0-15 | Filament: unified approvals inbox shell — one inbox, not seven | | **done** 2026-09-10 | pest 112 passed / 210 assertions · pint passed · phpstan 0 errors · 20 migrations clean · **console gate 5 passed** (inbox added). 8 tests, 3 of them rejections. |
| P0-16 | Deploy pipeline + staging subdomain working, deployed by push. **Day one, not at the end** | | **blocked** 2026-09-10 | Pipeline **built and tested**: CI (20 checks incl. the loop's whole Step 3 list), `deploy.sh`, `.env.staging.example`, `DEPLOY.md`. 14 tests. **Staging half needs a server** — Part D item 12. |
| P0-17 | Minimal `purchase_requisitions` + lines, and the **budget availability check** — PLAN.md section 5's first control. Needed because the Phase 0 exit gate is worded around a PR | | **done** 2026-09-10 | pest 137 passed / 244 assertions · pint passed · phpstan 0 errors · 22 migrations clean · console gate 5 passed. 11 tests, 4 of them rejections. No UI surface. |
| P0-18 | **Phase 0 exit gate**: PR raised → two-tier approval → **rejected by the budget check**, end to end | | **done (locally)** 2026-09-10 | pest 143 passed / 261 assertions · pint passed · phpstan 0 errors · 22 migrations clean · console gate 5 passed. 6 gate tests, 3 of them rejections. **The "on staging" clause is still unmet** — see Blocked. |

**Exit gate (PHASE-PLAN.md Part C).** A PR can be raised, routed through a two-tier approval, and
**rejected by the budget check** — end to end, on staging, deployed by push. Nothing else ships
until this holds.

---

## Phase 1 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for two things:

1. *Phase 0 exit gate passed.* Passed **locally** (P0-18, 6 tests). The
   *"on staging, deployed by push"* clause is **not** met — P0-16 is blocked on
   Part D item 12. Phase 1 opened anyway, on the user's explicit instruction.
2. *B2 answered — or the deck's bands accepted explicitly as placeholders, with a
   re-test scheduled.* B2 is **unanswered**. The bands are hereby accepted as
   placeholders, which is the plan's own second option. **The re-test is P1-17**,
   and it exists so this acceptance cannot be quietly forgotten.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P1-01 | `vendors` + `vendor_accreditations` (12-month expiry), status enum (`accredited`/`suspended`/`removed`) with reason and clearing user | **F11** | **done** 2026-09-10 | pest 157 passed / 284 assertions · pint passed · phpstan 0 errors · 24 migrations clean · console gate 5 passed. 14 tests. Bank details encrypted at rest. No UI surface. |
| P1-02 | `vendor_validation_visits` (dated, with verdict) and `vendor_bonds` (independent expiry) | **F15** | **done** 2026-09-10 | pest 166 passed / 303 assertions · pint passed · phpstan 0 errors · 26 migrations clean · console gate 5 passed. 9 tests. No UI surface. |
| P1-03 | `rfqs` + `rfq_recipients`. **Gate: an expired accreditation cannot receive an RFQ** | F15 | **done** 2026-09-10 | pest 183 passed / 331 assertions · pint passed · phpstan 0 errors · 29 migrations clean · console gate 5 passed. **17 tests, 12 of them rejections** — 6 added closing bypasses found by security review. No UI surface. |
| P1-03a | Filament: **Vendors screen** — register, accreditation, suspend/clear/remove actions. Pulled forward from P1-16 so the chain is visible as it is built | | **done** 2026-09-10 | pest 193 passed / 366 assertions · pint passed · phpstan 0 errors · 29 migrations clean · **console gate 7 passed** (vendor register added). 10 screen tests. |
| P1-04 | `quotes` + lines, `bid_tabulations`. **Gate: three quotes minimum before award** | | **done** 2026-09-10 | pest 207 passed / 391 assertions · pint passed · phpstan 0 errors · 32 migrations clean · console gate 7 passed. 14 tests, 8 of them rejections. No UI surface. |
| P1-05 | `sole_source_justifications` — written justification, approved one level above | | **done** 2026-09-11 | pest 219 passed / 407 assertions · pint passed · phpstan 0 errors · 33 migrations clean · console gate 7 passed. 12 tests, 6 of them rejections. No UI surface. |
| P1-06 | `purchase_orders` + lines. **Gate: no PO without an approved PR *and* a tabulated bid** | | **done** 2026-09-11 | pest 234 passed / 430 assertions · pint passed · phpstan 0 errors · 35 migrations clean · console gate 7 passed. 15 tests, 8 of them rejections. No UI surface. |
| P1-07 | `subcontracts`. **Gate: an expired bond blocks a subcontract award** | **F15** | **done** 2026-09-11 | 9 tests, 7 of them rejections. **F15 fully closed.** No UI surface. |
| P1-08 | `delivery_receipts`, `receiving_reports` with short-delivery capture. **Non-nullable PO reference** | | **done** 2026-09-11 | pest 255 passed / 462 assertions · pint passed · phpstan 0 errors · 38 migrations clean · console gate 7 passed. 12 tests, 6 of them rejections. No UI surface. |
| P1-09 | `inspections` + `return_to_vendor` | | **done** 2026-09-11 | 10 tests, 5 of them rejections. No UI surface. |
| P1-10 | `stock_cards`, `material_issuances`, `physical_counts` | | **done** 2026-09-11 | pest 275 passed / 492 assertions · pint passed · phpstan 0 errors · 45 migrations clean · console gate 7 passed. 10 tests, 4 of them rejections. No UI surface. |
| P1-11 | `three_way_matches` + `ap_vouchers` (withholding columns via the P0-09 macro). **Gate: no payment without a three-way match** | | **done** 2026-09-11 | pest 290 passed / 516 assertions · pint passed · phpstan 0 errors · 48 migrations clean · console gate 7 passed. 15 tests, 7 of them rejections. No UI surface. |
| P1-12 | `vendor_advances` — released against a PO, offset at the AP voucher; accepted by the match as a distinct non-matched payment type | **F8** | **done** 2026-09-11 | Built with P1-11 — the advance is recovered *at* the voucher, so the offset cannot ship an iteration later. **F8 closed.** No UI surface. |
| P1-13 | Equipment register: `equipment`, `equipment_assignments`, `equipment_costs`, `depreciation_schedules` | **F5** | **done** 2026-09-11 | pest 327 passed / 582 assertions · pint passed · phpstan 0 errors · 55 migrations clean. 15 tests, 6 of them rejections. **F5 closed.** No UI surface. |
| P1-14 | `vendor_scorecards` — four dimensions incl. document completeness; suspension rules incl. warranty-claim trigger | **F12** | **done** 2026-09-11 | 14 tests, 4 of them rejections/boundaries. **F12 closed**, and F11's warranty-claim trigger built. No UI surface. |
| P1-15 | **Material cost posts to the ledger** through the P0-13 Posting service | | **done** 2026-09-11 | 8 tests, 3 of them rejections. The exit gate's last clause. No UI surface. |
| P1-16 | Filament screens for the **remaining** chain documents, each added to the console gate (Vendors shipped early as P1-03a) | | **done** 2026-09-11 | pest 349 passed / 620 assertions · pint passed · phpstan 0 errors · 55 migrations clean · **console gate 17 passed** (9 screens added). 22 screen tests. |
| P1-17 | **B2 re-test** — re-run approval routing against the client's real peso limits once Part D item 1 is answered | | **blocked** 2026-09-11 | **The numbers are still unanswered — this cannot be marked done.** What is built is the re-test itself: bands moved to `config/approvals.php`, and `AuthorityMatrixContractTest` (10 tests) asserts the properties any set of bands must satisfy — contiguous, non-overlapping, open-ended at the top, every floor and ceiling routing to its own tier, sole source escalating exactly one. When B2 lands: edit the config, run the file. |
| P1-18 | **Phase 1 exit gate**: PR → AP voucher, sole source escalating one level, expired vendor rejected from an RFQ, short delivery on the DR, material cost in the ledger | | **done (locally)** 2026-09-11 | pest 366 passed / 679 assertions · pint passed · phpstan 0 errors · 55 migrations clean · console gate 17 passed · laravel.log clean. 7 gate tests, one per clause. **The "on staging" clause is still unmet** — same blocker as P0-16. |

**Exit gate (PHASE-PLAN.md Part C).** One full cycle: PR through AP voucher, with a
sole-source purchase escalating one level, an **expired-accreditation vendor rejected
from an RFQ**, and a **short delivery noted on the DR**. **Material cost appears in the
ledger.**

---

## Phase 2 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for one thing: *Phase 1 exit gate
passed.* It passed **locally** (P1-18, 7 tests, one per clause). Two things are carried forward
rather than resolved, and neither is code:

1. *"on staging"* — the gate is worded "one full cycle **on staging**". P0-16 is still blocked on
   a server (Part D item 12), so no phase in this build has satisfied a staging clause yet.
2. *B2* — the peso bands are still the deck's placeholders. P1-17 is `blocked`, not done, and the
   re-test harness now exists so answering it is a config change plus one test run.

Phase 2 opened on the same standing instruction that opened Phase 1.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P2-01 | `mobilizations` + checklist, and `permits`. **Gate: countersigned PO — countersignature is a state, not an attachment** | **F2** | **done** 2026-09-11 | pest 404 passed / 737 assertions · pint passed · phpstan 0 errors · 63 migrations clean. 13 tests, 7 of them rejections. **F2 closed.** No UI surface. |
| P2-02 | `billing_schedules` — the contract's milestone plan, per contract | | **done** 2026-09-11 | Built with P2-03 — a schedule without its document sets is the honour system F4 objects to. |
| P2-03 | `milestone_document_requirements` — required document set per milestone, configurable per contract type, checked **before submission opens** | **F4** | **done** 2026-09-11 | 13 tests, 5 of them rejections. Templates in `config/billing.php`, keyed by contract type. **F4 closed.** No UI surface. |
| P2-04 | `accomplishments` + `joint_surveys` — measured work, signed by both sides | | **done** 2026-09-11 | 12 tests, 6 of them rejections. No UI surface. |
| P2-05 | `billings` with the **approved-or-returned branch**; a returned billing keeps its deductions | | **done** 2026-09-11 | 14 tests, 7 of them rejections. `billing_deduction_blocks` outlives its billing — the clause the exit gate turns on. No UI surface. |
| P2-06 | `sales_invoices` + `official_receipts`, withholding columns via the P0-09 macro | | **done** 2026-09-11 | 12 tests, 5 of them rejections. **F9's other half** — the client withholds from us. No UI surface. |
| P2-07 | `retention_ledger` — retention withheld per billing, released at DLP | | **done** 2026-09-11 | Built with P2-09 — 12 tests, 6 of them rejections. A ledger, not a column. No UI surface. |
| P2-08 | AR aging + **30-day escalation to the project manager through a notification inbox** | **F13** | **done** 2026-09-11 | 13 tests, 3 of them boundaries. Named recipient, fires at 30, does not repeat within a bucket. **F13 closed.** |
| P2-09 | **Revenue and receivables post to the ledger** through the P0-13 Posting service | | **done** 2026-09-11 | Revenue posts at the invoice, at the gross. The exit gate's last clause. No UI surface. |
| P2-10 | Filament screens for the Phase 2 documents, each added to the console gate | | **done** 2026-09-11 | pest 471 passed / 850 assertions · pint passed · phpstan 0 errors · 71 migrations clean · **console gate 23 passed** (6 screens added). 16 screen tests. Demo seeder now runs the billing cycle too, including a **returned** billing. |
| P2-11 | **Phase 2 exit gate**: a 30% downpayment billing and one 50% progress billing post; one billing returned, re-measured and resubmitted in the next cutoff **without the deducted line reappearing** | | **done (locally)** 2026-09-11 | pest 479 passed / 871 assertions · pint passed · phpstan 0 errors · 71 migrations clean · console gate 23 passed · laravel.log clean. 8 gate tests; the central one walks May → returned → June in a single test. **The "on staging" clause is still unmet** — same blocker as P0-16. |

**Exit gate (PHASE-PLAN.md Part C).** A 30% downpayment billing and one 50% progress billing
post; one billing is returned, re-measured, and resubmitted in the next cutoff without the
deducted line reappearing. **Revenue and receivables appear in the ledger.**

---

## Phase 3 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for two things:

1. *Phase 2 exit gate passed.* Passed **locally** (P2-11, 8 tests). The staging clause is unmet
   for the third phase running — P0-16 is blocked on a server, not on code.
2. *B3 issued — payroll rules must be the SOP's rules, not the deck's defaults, before the first
   real cutoff.* **B3 has not been issued.** This is a harder entry condition than B2 was: an
   approval band routed wrongly is embarrassing, but a payroll rule applied wrongly is somebody's
   pay. The phase is opened on the same standing instruction, and **P3-13 is the re-test**, built
   the way P1-17 was — so the deck's defaults can be replaced with a configuration change.

**The data here is the most sensitive in the build.** `PLAN.md` §3 requires government numbers
and salary rates encrypted at rest **in the first migration that creates them**, not later.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P3-01 | `employees` (the 201 file) — government numbers and salary rates **encrypted at rest from the first migration** | | **done** 2026-09-11 | pest 508 passed / 921 assertions · pint passed · phpstan 0 errors · 75 migrations clean. 14 tests; two read the RAW columns to prove the database holds no plaintext. No UI surface. |
| P3-02 | `manpower_requisitions` — hiring request, approved before onboarding | | **done** 2026-09-11 | Built with P3-03 — the requisition is what a contract is issued against. No UI surface. |
| P3-03 | `employment_contracts`. **Gate: signed before first shift** | | **done** 2026-09-11 | 15 tests, 9 of them rejections. Gate registered in `GateServiceProvider`; a backdated signature is refused. No UI surface. |
| P3-04 | `timelogs` + biometrics CSV import, with a rejected-row report rather than a silent skip | | **done** 2026-09-11 | pest 523 passed / 950 assertions · pint passed · phpstan 0 errors · 77 migrations clean. Rejected rows return with their line numbers. No UI surface. |
| P3-05 | `daily_time_records` + validation. **"No timelog, no pay"** — the held-day mechanic: an unvalidated day carries to the next cutoff rather than being dropped | | **done** 2026-09-11 | 15 tests, 6 of them rejections. `held_from_period_end` and `paid_in_period_end` carry the mechanic. No UI surface. |
| P3-06 | `overtime_authorities` — OT and night differential **approved in writing** before they are payable | | **done** 2026-09-11 | 13 tests, 6 of them rejections. Payable premium hours are the smaller of worked and authorised. No UI surface. |
| P3-07 | `payroll_runs`, queued, on the semi-monthly cutoff (1–15, 16–EOM) | | **done** 2026-09-11 | Built with P3-08. `ComputePayrollRun` is the build's first queued job. A run straddling two cutoffs is refused. No UI surface. |
| P3-08 | Gross pay computed **from validated DTR only**, with statutory deductions (SSS, PhilHealth, Pag-IBIG, withholding) | | **done** 2026-09-11 | pest 558 passed / 1021 assertions · pint passed · phpstan 0 errors · 81 migrations clean · laravel.log clean. 22 tests (15 run + 7 statutory). **Rates are placeholders — Part D item 9.** No UI surface. |
| P3-09 | **Payroll register variance against the last cutoff, per project, explained in writing — and it BLOCKS approval** | **F10** | **done** 2026-09-11 | pest 571 passed / 1044 assertions · pint passed · phpstan 0 errors · 82 migrations clean · laravel.log clean. 13 tests, 7 of them rejections. Gate registered in `GateServiceProvider`. **F10 closed.** No UI surface. |
| P3-10 | `payslips` (PDF) and the disbursement file — bank upload **or** cash payout with acknowledgment | | **done** 2026-09-11 | pest 587 passed / 1079 assertions · pint passed · phpstan 0 errors · 85 migrations clean · laravel.log clean. 16 tests, 8 of them rejections. dompdf 3.1.6 added. Files on the **private** disk. No UI surface. |
| P3-11 | **Labor cost posts to the ledger** through the P0-13 Posting service | | **done** 2026-09-11 | pest 597 passed / 1099 assertions · pint passed · phpstan 0 errors · 86 migrations clean · laravel.log clean. 10 tests, 4 of them rejections. Per project, at gross, on the payroll calendar. No UI surface. |
| P3-12 | Filament screens for the Phase 3 documents, each added to the console gate | | **done** 2026-09-11 | pest 614 passed / 1139 assertions · pint passed · phpstan 0 errors · 86 migrations clean · **console gate 28 passed** (5 screens added) · laravel.log clean. 17 screen tests. Demo seeder now runs a payroll cutoff, with a **held day**. |
| P3-13 | **B3 re-test** — payroll rules re-run against the client's SOP once it is issued | | **blocked** 2026-09-11 | **The SOP has not been issued — this cannot be marked done.** What is built is the re-test: `PayrollRulesContractTest` (11 tests, **108 assertions**) asserts the properties ANY schedule must satisfy — floors and ceilings that bite, credits rounded not truncated, contiguous ascending brackets, tax on the excess over a floor, monotonic take-home, contributions before tax. When B3 lands: edit `config/payroll.php`, run the file. |
| P3-14 | **Phase 3 exit gate**: one semi-monthly cutoff runs with an unvalidated day **held and paid in the following cutoff**. Labor cost appears in the ledger | | **done (locally)** 2026-09-11 | pest 632 passed / 1276 assertions · pint passed · phpstan 0 errors · 86 migrations clean · console gate 28 passed · laravel.log clean. 7 gate tests; the central one walks both cutoffs in a single test. **The "on staging" clause is still unmet** — same blocker as P0-16. |

**Exit gate (PHASE-PLAN.md Part C).** One semi-monthly cutoff runs on staging with an unvalidated
day held and paid in the following cutoff. **Labor cost appears in the ledger.**

---

## Phase 4 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for three things:

1. *Phase 3 exit gate passed.* Passed **locally** (P3-14, 7 tests). The staging clause is unmet
   for the fourth phase running — P0-16 is blocked on a server, not on code.
2. *Part D item 11 answered — BIR CAS registration.* **Unanswered.** This one is different in
   kind from the placeholders the build has been absorbing: CAS registration is a regulatory
   filing about the system itself, and it cannot be defaulted. Phase 4 builds the ledger
   consolidation regardless; what it cannot do is make the output a registered book of account.
3. *Part D item 13 answered — payroll headcount.* **Unanswered.** Above 1,000 employees, NPC
   registration and a designated Data Protection Officer become obligations. Phase 3 encrypted
   everything PLAN.md §3 names, which is the part the build controls.

Phase 4 opened on the same standing instruction as Phases 1 to 3.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P4-01 | `expenses` — capture with a **mandatory cost code**, and the reason a rejection is permanent | | **done** 2026-09-11 | pest 647 passed / 1296 assertions · pint passed · phpstan 0 errors · 88 migrations clean · laravel.log clean. 15 tests, 8 of them rejections. Barred from the PERIOD, keyed on the receipt. No UI surface. |
| P4-02 | `cash_advances` + `liquidations`, with the unliquidated balance derived rather than stored | | **done** 2026-09-11 | 12 tests, 6 of them rejections. Balance derived; a liquidation points at a real captured expense. No UI surface. |
| P4-03 | The **OPEX calendar as a scheduler-driven state machine**: day 1–25 capture · 26 cutoff · 27 coding · 28 validation · 29 consolidation · 30 budget review · 3–5 reporting *(following month)* | **F3** | **done** 2026-09-11 | Built with P4-04 — the sweep is part of the cutoff transition, so a cut-off period cannot exist without it having run. Stages advance one at a time, never backwards. |
| P4-04 | **Day-26 job: unliquidated advances charge to the next payroll.** A scheduled cross-chain write into HRIS | **F17** | **done** 2026-09-11 | pest 672 passed / 1343 assertions · pint passed · phpstan 0 errors · 92 migrations clean · laravel.log clean. 13 tests. **F17 closed** — the deduction is a real row, and a unique key stops one advance being swept twice. No UI surface. |
| P4-05 | Budget vs actual per cost code, from the ledger | | **done** 2026-09-11 | Built with P4-06. Actual is summed from captured expenses; a returned one is not booked and does not count. |
| P4-06 | **Variance explanations block the month-end close** — the OPEX twin of F10's payroll gate | | **done** 2026-09-11 | 15 tests. Above 10%, so 10% exactly passes and 12% blocks — the exit gate's own figure. Underspend blocks too. Gate registered in `GateServiceProvider`. |
| P4-07 | **Overhead posts to the ledger** through the P0-13 Posting service | | **done** 2026-09-11 | pest 687 passed / 1372 assertions · pint passed · phpstan 0 errors · 93 migrations clean · laravel.log clean. One row per cost code, on the OPEX calendar. **The fourth and last chain to reach the ledger.** No UI surface. |
| P4-08 | Ledger consolidation and the **project P&L** — revenue, material, subcontract, labour, overhead | | **done** 2026-09-11 | Built with P4-09. Assembled by SUMMING the ledger, never by re-classifying. A reversed posting and its reversal net to zero. |
| P4-09 | **Cash requirement for the next month** as a consolidation output | **F16** | **done** 2026-09-11 | pest 699 passed / 1400 assertions · pint passed · phpstan 0 errors · 93 migrations clean · laravel.log clean. 12 tests. **F16 closed** — five figures, none of them a P&L figure. No UI surface. |
| P4-10 | Filament screens for the Phase 4 documents, each added to the console gate | | **done** 2026-09-11 | pest 715 passed / 1432 assertions · pint passed · phpstan 0 errors · 93 migrations clean · **console gate 33 passed** (5 screens added) · laravel.log clean. 16 screen tests. Demo seeder now runs an OPEX month, **left blocked at budget review**. |
| P4-11 | **Phase 4 exit gate**: one month-end close — an expense rejected for a missing cost code and **permanently barred from the period**, and a **12% variance blocking the close** until explained. Overhead appears in the ledger | | **done (locally)** 2026-09-11 | pest 725 passed / 1455 assertions · pint passed · phpstan 0 errors · 93 migrations clean · console gate 33 passed · laravel.log clean. 10 gate tests; one walks every stage of a month end to end. **The "on staging" clause is still unmet** — same blocker as P0-16. |

**Exit gate (PHASE-PLAN.md Part C).** One month-end close on staging: an expense rejected for a
missing cost code and permanently barred from the period, and a 12% variance blocking the close
until explained. **Overhead appears in the ledger.**

---

## Phase 5 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for two things:

1. *Phase 4 exit gate passed.* Passed **locally** (P4-11, 10 tests). The staging clause is unmet
   for the fifth phase running — P0-16 is blocked on a server, not on code.
2. *B4 audit underway — the paper audit and this phase should overlap, so audit findings land
   while the close-out flows are still soft.* **No audit has been commissioned.** This one cannot
   be worked around the way a threshold can: an audit is people reading paper, and the build
   cannot do it on their behalf.

Carried forward unchanged: the staging clause, **B3** (payroll SOP) and **Part D items 11 and 13**
(BIR CAS registration, payroll headcount).

**Slide 9's structural obligation**, which shapes most of this phase: *"Close-out is a checklist
with named clearers per line, not a status flag."* And: back-charges are computed at step 2 and
applied at step 4, which makes **step 2 a hard predecessor of step 4 across two different chains**.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P5-01 | `substantial_completions` + `punchlists` with per-item clearing | | **done** 2026-09-12 | pest 745 passed / 1503 assertions · pint passed · phpstan 0 errors · 96 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 20 tests. **Three CHECK constraints**, each probed by name against the live schema: `punchlist_items_clearance_is_signed`, `punchlist_items_responsibility_is_attributed`, `punchlists_closure_is_signed`. |
| P5-02 | `back_charges` against subcontracts, computed at punchlist clearing — **ledger postings that reduce a subcontractor's payable and increase project cost** | **F6 — closed** | **done** 2026-09-12 | pest 759 passed / 1532 assertions · pint passed · phpstan 0 errors · 97 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 14 tests. **One leg posted, not two** — the cost; the recovery is derived. `back_charges_amount_is_positive` probed by name. |
| P5-03 | `warranties` register linking certificate to vendor; `warranty_claims` already feeds F11's suspension trigger | **F7 — closed** | **done** 2026-09-13 | pest 780 passed / 1577 assertions · pint passed · phpstan 0 errors · 99 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 21 tests, 12 of them rejections. **Coverage is a period and the operative date is the failure**, not the filing. `warranties_coverage_period_is_ordered` and the composite FK `warranty_claims_warranty_vendor_foreign` (warranty_id, vendor_id) → warranties(id, vendor_id) both probed by name against the live schema. |
| P5-04 | Turnover pack and acceptance — as-built drawings, manuals, certificate of completion, warranty certificates, permits | | **done** 2026-09-13 | pest 804 passed / 1629 assertions · pint passed · phpstan 0 errors · 101 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 24 tests, 15 of them rejections. **Two kinds of line**: filed by a named person, or answered by the register and not fileable at all. Three CHECK constraints probed by name: `turnover_packs_acceptance_is_signed`, `turnover_pack_items_filing_is_signed`, `turnover_pack_items_register_lines_are_not_filed`. |
| P5-05 | **Final billing applies deductions and back-charges BEFORE the invoice is raised** | F6 | **done** 2026-09-13 | pest 824 passed / 1672 assertions · pint passed · phpstan 0 errors · 103 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 20 tests, 11 of them rejections. The invoice is computed FROM the deducted net, and the gate `final-billing-deductions-applied` refuses an invoice over a back-charge missing from the statement. `final_billing_deductions_amount_is_positive` and `final_billing_deductions_back_charge_is_typed` probed by name. |
| P5-06 | Demobilization and clearance: equipment and IT asset return, final pay clearance | | **done** 2026-09-13 | pest 844 passed / 1710 assertions · pint passed · phpstan 0 errors · 105 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 20 tests, 12 of them rejections. **A person is a named act; plant and money are derived** from the equipment register and P4-02's advances. The cross-chain rule: an employee holding an unliquidated advance cannot be cleared for final pay. `demobilizations_completion_is_signed` and `demobilization_clearances_clearance_is_signed` probed by name. |
| P5-07 | **Retention release at DLP end**, and the collection that closes the project | | **done** 2026-09-13 | pest 864 passed / 1750 assertions · pint passed · phpstan 0 errors · 106 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 20 tests, 13 of them rejections. **Released is not collected** — the claim is its own document and the ledger moves when the money lands, through P2-07's `RetentionService::release()`. `ProjectCloseoutService::close()` is refused while retention, an invoice or a demobilization is outstanding. `retention_releases_amount_is_positive` and `retention_releases_collection_is_signed` probed by name. |
| P5-08 | `close_out_checklists` — **named clearer and timestamp per line, never a status flag** | | **done** 2026-09-13 | pest 883 passed / 1788 assertions · pint passed · phpstan 0 errors · 108 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 19 tests, 11 of them rejections. Slide 9's three panels, 11 lines, **no status column anywhere**. Nine lines are evidence-backed and refuse a signature the build knows to be untrue; two say `manual` out loud until P5-09. The checklist is now a precondition of `ProjectCloseoutService::close()`. `close_out_checklist_items_clearance_is_signed` probed by name. |
| P5-09 | Final project P&L and forecast to completion; vendor and subcontractor scorecards filed | | **done** 2026-09-13 | pest 900 passed / 1825 assertions · pint passed · phpstan 0 errors · 110 migrations clean · console gate 33 passed (no UI surface this task) · laravel.log clean. 17 tests, 8 of them rejections. **Life to date, not one month**, and filed as a snapshot because the forecast half keeps moving. `vendor_scorecards` learned to rate a SUBCONTRACT — the half P1-14 could not build. Both of P5-08's `manual` lines upgraded to evidence-backed. `final_accounts_cost_is_not_negative` and `vendor_scorecards_rates_exactly_one_subject` probed by name. |
| P5-10 | Filament screens for the Phase 5 documents, each added to the console gate | | **done** 2026-09-13 | pest 925 passed / 1874 assertions · pint passed · phpstan 0 errors · 110 migrations clean · **console gate 41 passed** (8 screens added) · laravel.log clean. 25 screen tests. Eight read-only resources plus the **close-out report view page** — the exit gate's second sentence rendered, panel by panel. |
| P5-11 | **Phase 5 exit gate**: a project reaches close-out and **cannot be closed until retention is collected**. The close-out report names who cleared each item and when | | **done (locally)** 2026-09-13 | pest 932 passed / 1898 assertions · pint passed · phpstan 0 errors · 110 migrations clean · console gate 41 passed · laravel.log clean. 7 gate tests; the central one walks certificate to closed book in a single test and is refused at the moment retention is **claimed but unpaid**. **The "on staging" clause is still unmet** — same blocker as P0-16. |

**Exit gate (PHASE-PLAN.md Part C).** A project reaches close-out and **cannot be closed** until
retention is collected. The close-out report names who cleared each item and when.

---

## Phase 6 — tasks

**Entry conditions, stated honestly.** `PHASE-PLAN.md` asks for two things:

1. *All chains posting to the ledger.* **Met.** Material (P1-15), revenue and receivables
   (P2-09), labour (P3-11) and overhead (P4-07) all reach `project_cost_ledger` through the
   P0-13 Posting service, and P5-09's final account is summed from it.
2. *B4 audit complete, findings triaged.* **Not met, and not workable around.** No audit has
   been commissioned. Phase 5 recorded the same gap; a phase later it is an *exit gate* clause
   rather than an entry one, which means Phase 6 can be built but **cannot be closed** on it.

Carried forward unchanged: the staging clause (P0-16), **B3** (payroll SOP) and **Part D items
11 and 13** (BIR CAS registration, payroll headcount).

**This phase divides cleanly, and the division belongs up front.** Some of it is code this build
can write and prove — row-level policies, the activity-log export, index work, 2FA, and a restore
rehearsal. The rest is server work on infrastructure that does not exist: fail2ban, SSH
hardening, PHP-FPM and MySQL tuning, Horizon in production, log rotation. Those are written as
configuration and runbook, and marked `blocked` on Part D item 12 rather than claimed.

| # | Task | Closes | Status | Evidence |
|---|---|---|---|---|
| P6-01 | **Per-project row-level policies** — a user sees only the projects they are assigned to, enforced in the query rather than in the view | | **done** 2026-09-13 | pest 943 passed / 1913 assertions · pint passed · phpstan 0 errors · 111 migrations clean · console gate 41 passed · laravel.log clean. 11 tests. A global Eloquent scope, not a view filter: a hidden row stays unaddressable by id, by relation and by export. **No assignment means no projects, never all of them**; unauthenticated means unscoped, because a queued payroll run has no user. |
| P6-02 | **Activity-log export** — the audit trail, extractable, with the columns an auditor asks for | | **done** 2026-09-13 | pest 954 passed / 1931 assertions · pint passed · phpstan 0 errors · 111 migrations clean · console gate 41 passed · laravel.log clean. 11 tests. A CSV on the **private** disk, nine fixed columns, inclusive date range, and `php artisan activity:export`. Deliberately **unscoped** — the opposite job from P6-01, because a partial audit trail that looks complete is worse than none. |
| P6-03 | **Indexes checked against five years of simulated document volume**, with the seeded volume and the measured queries recorded | | **done** 2026-09-14 | pest 968 passed / 1996 assertions · pint passed · phpstan 0 errors · 112 migrations clean · console gate 41 passed · laravel.log clean. 11 tests + 3 CSV-injection tests. 2,400 ledger rows over 60 months; **plans asserted from EXPLAIN, never wall-clock**. Found and closed a real gap: the P&L index was (project_id, category) while every P&L also ranges on `document_date`. |
| P6-04 | **Backup and restore**: the procedure, the rehearsal command, and a restore verified against a scratch database | **Exit gate clause 2** | **done (locally)** 2026-09-14 | pest 979 passed / 2016 assertions · pint passed · phpstan 0 errors · 112 migrations clean · console gate 41 passed · laravel.log clean. 11 tests, 5 of them refusals. `php artisan backup:rehearse` dumps, restores onto a scratch database and **counts the ledger's two triggers** — a restore that returns every row and loses the append-only guarantee passes a row count and fails this. `RUNBOOK.md` written. **Not yet rehearsed on a server** — Part D item 12. |
| P6-05 | **2FA enforced on every Finance, HR and Admin account** — exit gate clause 4 | Exit gate clause 4 — enrolment half | **done, with a stated gap** 2026-09-14 | pest 999 passed / 2044 assertions · pint passed · phpstan 0 errors · 113 migrations clean · console gate 41 passed · laravel.log clean. 20 tests. Secret **encrypted at rest**, enable separate from confirm, used codes cannot be replayed, and the middleware stops a covered account at the panel door until it enrols. Enforces ENROLMENT; the per-login half is P6-05a, now also done. |
| P6-05a | **The per-login TOTP challenge** — the other half of what "2FA is enforced" means | **Exit gate clause 4 — closed** | **done** 2026-09-14 | pest 1008 passed / 2065 assertions · pint passed · phpstan 0 errors · 113 migrations clean · **console gate 41 passed, answering a real TOTP challenge** · laravel.log clean. 9 tests. Once per session, session state never a column, session id regenerated before the pass is recorded, and **no test bypass anywhere** — the browser gate computes the code the way a phone does. |
| P6-06 | Failed-job alerting, log rotation and the operational schedule | | **done** 2026-09-14 | pest 1025 passed / 2093 assertions · pint passed · phpstan 0 errors · 114 migrations clean · console gate 41 passed · daily logs clean. 17 tests. A queue failure raises an alert addressed to a role and held open until a **named** person acknowledges it; repeats fold into one alert. Logs rotate daily, 30 days, **proven by a probe line landing in the dated file**. `job_failure_alerts_acknowledgement_is_signed` probed. |
| P6-06b | **Horizon** on Redis supervising the workers | | **blocked** 2026-09-14 | Verified, not assumed: `composer require laravel/horizon --dry-run` refuses on missing `ext-pcntl` and `ext-posix`, and there is no Redis. Install steps are in `RUNBOOK.md` section 7. The alerting above needs no change under Horizon — it listens for `JobFailed`, which Horizon workers raise. |
| P6-07 | Server hardening: fail2ban, SSH, PHP-FPM and MySQL tuning | | **done (config) / blocked (applied)** 2026-09-14 | pest 1045 passed / 2131 assertions · pint passed · phpstan 0 errors · 114 migrations clean · console gate 41 passed · daily logs 0 new ERROR/CRITICAL. 20 tests. Five files in `ops/`, each silent-failure setting pinned. **Found and reproduced a real defect:** with MySQL 8's default binary logging, a least-privilege user is refused `CREATE TRIGGER` (ERROR 1419) — the first `migrate --force` on a server would fail on the ledger's immutability migration. Also: X Protocol listens on `*` by default; SSH drop-in must be `00-` to beat cloud-init. **Not applied — Part D item 12.** |
| P6-08a | **Cost per unit of accomplishment** — PLAN.md §7 step 9, never built; found when the exit gate walked §7 | | **done** 2026-09-14 | pest 1070 passed / 2189 assertions · pint passed · phpstan 0 errors · 114 migrations clean · console gate 41 passed · daily logs 0 new ERROR/CRITICAL. 8 tests. Life-to-date cost ÷ **verified** accomplishment, benchmarked against the **open** budget; nothing verified reads *not measurable*, never zero. On the P&L page beside each project. |
| P6-08b | **The demo seed guard** — §7 requires the reset "can never point at production"; promised in two seeder docblocks and never built. Also: every seeded account had the password `password` in every environment | | **done** 2026-09-14 | pest 1070 passed / 2189 assertions · pint passed · phpstan 0 errors · 114 migrations clean · console gate 41 passed · daily logs 0 new ERROR/CRITICAL. 10 tests. Production refused before any row — **verified on the real command line: exit 1, refused before it even connected** to the database. Outside local/testing, no seeded account (admin or approver) may use the default password. |
| P6-01a | **Per-screen access by role** — P6-01 limited which PROJECTS a user sees; nothing limited which SCREENS. P0-14 deferred it to Phase 6 and it was never built | | **done** 2026-09-14 | pest 1159 passed / 2312 assertions · pint passed · phpstan 0 errors · console gate 41 passed · daily logs 0 new ERROR/CRITICAL. 48 tests in `ScreenAccessTest`. Enforced on all 36 resources AND all 41 resource pages, checked by direct URL: before it, a user with no role got **200 on the vendor create/edit pages and the approval-matrix create page**. Fail closed; map in `config/access.php` (Part D item 5 placeholders). |
| P6-08 | **Phase 6 exit gate**: the §7 demo walk-through end to end with gates rejecting; restore verified; B4 closed; 2FA enforced | | **green locally on what code can prove — NOT closed** 2026-09-14 | pest 1070 passed / 2189 assertions · pint passed · phpstan 0 errors · 114 migrations clean · console gate 41 passed · daily logs 0 new ERROR/CRITICAL. 7 gate tests on the **seeded demo**, not on fixtures. Clause 1 walk-through: four ledger categories, over-budget PR refused at `submit`, OPEX close refused, P&L and cost per unit assembled, production seeding refused. Clause 2: rehearsal wired and scheduled. Clause 4: status command green. **Unmet, and not claimable by code:** clause 1 "on staging" (P0-16, Part D item 12) and clause 3 (B4). |

**Exit gate (PHASE-PLAN.md Part C), all four required.** 1. The §7 demo walk-through runs start
to finish **on staging**, gates rejecting as designed. 2. A backup has been restored onto a
scratch database and the restore verified. 3. B4's one-cycle audit is complete and its findings
closed or accepted in writing. 4. 2FA is enforced on every Finance, HR and Admin account.

**Two of those four cannot be met by code**: clause 1 needs the staging server (P0-16, Part D
item 12) and clause 3 needs an audit somebody commissions. They are named here so the phase
cannot be quietly declared done on the two that can.

---

## Operational screens — tasks (gap found 2026-09-14)

> **A correction to how Phases 1–5 were built, recorded here per BUILD-LOOP's rule that a wrong plan is
> fixed and said so.** 29 of 40 screens were built list-only: no create, no action, no way to start
> the work they show. The resource docblocks justified it as "read-only; a form would be a second way to
> write the row". **PLAN.md never said that.** It chose Filament *for* "resource CRUD, form builder,
> approval-style Actions with confirmation forms" and says an ERP "is registers, forms, approvals".
> The real rule is narrower and still stands: **every write goes through the domain service** (PLAN.md
> §3) and every control is a service-layer gate, never a UI-only check (§5). A screen action that calls
> the service is exactly what the plan intended; a screen with no action leaves a working chain
> unreachable. Found by the client: "some features don't have CRUD, example on finance".
>
> **What CRUD means per kind of record.** Master data (employees, vendors, projects, cost codes,
> equipment, accounts): add and edit, no delete where history points at it. Documents (PR, PO, AP
> voucher, billing, expense, payroll run…): create, then act — submit, approve or return, pay, cancel —
> never free edit after submission, never delete. The ledger: append-only; correction by reversal.
>
> Each task: TDD (the refusal tests first), the full check list, a console-gate entry per new form,
> one commit.

| ID | Module | Screens | Status |
|---|---|---|---|
| OS-00 | Setup and benefits | Projects, cost codes, budgets, equipment, accounts; allowances, leave, 13th month | done (iterations 76–77) |
| OS-01 | Finance · Payables | Three-way matches, AP vouchers (raise, release advance) | **done** |
| OS-02a | Finance · Contracts | Contracts (draft, send, sign, terminate) and milestone documents | **done** |
| OS-02b | Finance · Billing | Accomplishments, billings, sales invoices and collections, AR escalations | **done** |
| OS-03 | Finance · OPEX | OPEX periods, expenses, cash advances, overhead posting, budget-vs-actual explanations, payroll deductions | **partial** — periods, variance explanations and expense return done; cash advances remain |
| OS-04 | Finance · Ledger | Ledger reversal; labour, material and revenue posting actions | todo |
| OS-05 | Procurement | Requisitions, RFQs, quotes and tabulation, sole source, purchase orders, subcontracts, vendor scorecards | **done** (scorecards remain) |
| OS-06 | Warehouse | Receiving, inspection and return to vendor, stock issue and count | **done** |
| OS-07 | Payroll operations | Manpower requests and contracts, DTR import and validation, overtime, payroll runs, disbursements | **done** (manpower requests and contracts remain) |
| OS-08 | Operations | Mobilization checklists, permits | todo |
| OS-09 | Close-out | Completion certificate, punchlists, back charges, warranties, turnover, retention release, demobilization, final account, project close | todo |
| OS-10 | Exit check | A test that every panel screen either offers a way to start its work or is declared a report | todo |

**Folded into the same run on the client's instruction (2026-09-14), after an audit of what else was missing
beyond screens.** Each is named in PLAN.md §2 and was never built:

| ID | What | Why it matters |
|---|---|---|
| OS-11 | Excel import and export (`maatwebsite/excel`) | PLAN.md §2 specifies it for the **biometric timelog CSV import** and register/BvA exports. `TimekeepingService::import()` takes rows and nothing can get a file to it, so every DTR would be typed by hand — which is what the import exists to prevent. |
| OS-12 | Printed documents | PLAN.md §2 names four: PO, billing form, payslip, abstract of canvass. **Only the payslip exists.** A chain that cannot print a PO to send a vendor is not finished. |
| OS-13 | File attachments (`spatie/laravel-medialibrary`) | Installed since Phase 0 and **used by nothing** — no model implements `HasMedia`. Milestone documents, permits, receipts and vendor certificates record a reference string only, so the signed copy lives somewhere else entirely. Storage choice affects backup and PLAN.md §3's Data Privacy obligations. |
| OS-14 | Dashboard widgets | **done** — five widgets, access-controlled and project-scoped. |
| OS-15 | Notifications | PLAN.md §2 names the notification bell. Nothing notifies anybody: AR escalations, waiting approvals and job failures are all rows somebody must remember to open, so F13's "an escalation arrives" is only half true. |

---

## Evidence

Filled by the loop from real command output. Never estimated.

| Check | Last result | When |
|---|---|---|
| `php artisan test` | **pest: 1507 passed**, 3436 assertions, 0 failures | 2026-09-14 |
| `./vendor/bin/pint --test` | **passed** | 2026-09-13 |
| `./vendor/bin/phpstan analyse` | **passed**, 0 errors (level 5) | 2026-09-13 |
| `migrate:fresh --seed` | **clean** — 114 migrations, admin + 12 matrix rows + 5 vendors + **all four demo chains: procurement, billing, payroll, OPEX** | 2026-09-13 |
| laravel logs clean | **0 ERROR/CRITICAL** across `storage/logs/laravel-*.log` — daily files since P6-06 | 2026-09-14 |
| Browser console clean | **npm run test:console — 52 passed**, 0 errors, 0 warnings | 2026-09-13 |

`n/a` means the check could not run, **not** that it passed. Every row must read as a real
result before the Phase 0 exit gate is claimed.

---

## Blocked

**P0-16 - the staging half. Needs a server, not code.**

**This is now the ONLY thing between the build and a closed Phase 0.** The exit
gate's behaviour is demonstrated end to end and green (P0-18); the clause that
remains unsatisfied is *"on staging, deployed by push"*, and it cannot be
satisfied by writing code.

Built, tested and committed: `.github/workflows/ci.yml` (the loop's entire Step 3
check list, on MySQL 8.4, plus `composer audit`), `deploy.sh` (no schema-dropping
command can appear in it - a test enforces the names are absent as literal
strings), `.env.staging.example`, and `DEPLOY.md`.

Not done, and not doable from here:

1. A VPS provisioned - **Part D item 12**, the ~$17/mo line: Hetzner CX22 or
   DigitalOcean in Singapore, plus Ploi or Laravel Forge. The management layer is
   the security purchase, not an optional extra.
2. `staging.<domain>` resolving, with its own database and a least-privilege user.
3. A push actually deploying.

**This blocks the Phase 0 exit gate**, which is worded *"end to end, on staging,
deployed by push"*. Every other part of that gate exists and is tested locally;
the clause that cannot be satisfied is the one about staging.

Nothing else in Phase 0 is outstanding. `DEPLOY.md` section 2 has the exact steps
for once the server exists.

---

## Log

One line per iteration, newest last.

- 2026-09-10 · **iter 1** · P0-01 done. Corrected `PLAN.md` §1: chains 2, 3 and 4 were scoped to
  phases 2–3 / 3–4; slide 2's grid gives all four chains a phase-1 and a phase-4 cell, so all
  four now read 1→4. Added the per-chain evidence table. Docs only — no app exists yet, so the
  Step 3 checks are recorded `n/a`, not clean. Next: P0-02 `laravel new`.
- 2026-09-10 · **iter 2** · P0-02 done. Scaffolded the app. Two plan assumptions were stale:
  `laravel new` gives **Laravel 13.31**, not 12, and **Filament 5** now exists. Confirmed with
  the user to stay on **Filament 4.13** (verified it resolves on Laravel 13); Laravel 13 kept as
  scaffolded. MySQL 8.4 wired for both `construction` and `construction_test`. Switched the
  test suite off in-memory SQLite onto MySQL — PLAN.md §2 picks MySQL for `SELECT … FOR UPDATE`
  on gapless numbering, so P0-06 must be tested on the real engine. Seeder now creates the
  Filament admin so `migrate:fresh --seed` leaves a usable app. Next: P0-03 test toolchain.
- 2026-09-10 · **iter 3** · P0-03 done. Pest 5 needs PHP 8.4 and only 8.3.30 is installed, so
  Pest 4.7.8, with phpunit downgraded 12.5.35 to 12.5.33 (still inside Laravel's ^12.5.12).
  PHPStan took 4 attempts — one past the 3-strike rule, pushed through because the last was
  root-caused, not guessed: Pest's Laravel helpers are namespaced PestLaravelget(), so the
  fix is a real 'use function' import rather than a suppression. The console check is now a
  committed test (npm run test:console) instead of something driven by hand — it fails on any
  console error, warning, pageerror or HTTP >=400. Its login selectors target input ids:
  Filament emits no <label for> server-side and Alpine sets the password field's type at
  runtime, so label- and type-based locators were racing hydration. Next: P0-04 spatie.
- 2026-09-10 · **iter 4** · P0-04 done. permission 8.3.0 and medialibrary 11.23.7 are current;
  activitylog capped at 4.12.3 because 5.1.1 needs PHP ^8.4 — second package PHP 8.3.30 has held
  back, now recorded in DECISIONS-PENDING.md as a deliberate choice rather than a silent one.
  User carries HasRoles and LogsActivity; getActivitylogOptions logs only name and email, dirty
  only — password is deliberately excluded so the hash never reaches the audit trail. Six wiring
  tests, including one asserting a permission is DENIED when never granted, since a permission
  system that only proves grants proves nothing. Medialibrary is schema-only for now: model
  wiring lands with the first attachment-bearing document in Phase 1, so that task is not also a
  migration task. Next: P0-05 core models.
- 2026-09-10 · **iter 5** · P0-05 done. The project spine: `organizations`, `projects`,
  `cost_codes`, `budgets`, `budget_lines`. **F1 closed** — `ProjectPhase` carries all four
  phases and a test walks every case, so no chain can be scoped to a phase subset by omission.
  Eight of the thirteen tests are rejections, because the schema-level controls are the point:
  the phase enum, the unique project code, the scoped-unique cost code, the non-nullable
  `budget_lines.cost_code_id` that PLAN.md §5's budget check rests on, and one line per cost
  code per budget so availability resolves to a single number. Two of those insert through the
  query builder rather than Eloquent, proving the database refuses them and not just the model.
  A WBS cycle guard throws `DomainException` on save — a cycle makes every rollup
  non-terminating and month-end close is the wrong place to discover it.
  Three things were fixed rather than worked around. Larastan typed
  `budget_lines.amount` as **float**, exactly the type PLAN.md §4 forbids money from being; the
  `decimal:4` cast returns a string, so the model now declares it. `ProjectFactory` used Faker's
  `catchPhrase()`, which is en_US-only and undeclared — replaced with a locale-independent call.
  And `toBeMoney()`, added in P0-03 and unused until now, turned out to be invisible to PHPStan:
  a Pest expectation registered at runtime reports "undefined method" on first use, and every
  route to quieting that is a suppression this build forbids. It is now a plain
  `expectMoney()` function — a money rule static analysis cannot see is one that gets broken
  quietly. **MySQL was down at the start of this iteration** and was started via the Laragon CLI;
  four of the six checks are unrunnable without it. Next: P0-06 numbering service.
- 2026-09-10 * **iter 6** * P0-06 done. `document_sequences` and
  `App\Domain\Numbering\DocumentNumberGenerator` - the first of PLAN.md section 3's four
  cross-cutting services. A number is read from a counter row under `SELECT ... FOR UPDATE`
  inside the caller's transaction, formatted `PR-2026-00001`. Two tests carry the weight: one
  asserts the `for update` clause really reaches MySQL by reading it back off the query log,
  because a lock you believe in but never verified is not a lock; the other proves a rolled-back
  document insert **takes its number back** rather than leaving a hole, which is what "gapless"
  actually has to mean - an auditor reading PR-2026-00141 then PR-2026-00143 is entitled to ask
  about 142. A third asserts the counter is a stored row that advances with no documents written
  at all, which is what distinguishes this from the `MAX(id)+1` scheme PLAN.md forbids by name.
  The counter is created with `insertOrIgnore` so a concurrent first-issue loses the race against
  the unique key harmlessly instead of erroring. Document types are validated to a bare uppercase
  prefix - the prefix is parsed back out of printed references in every chain, so `pr-1` is
  rejected rather than quietly stored. **Not proven here:** true multi-process contention. The
  lock clause is verified, but a single-threaded suite cannot demonstrate two connections actually
  blocking on each other; that wants a concurrency test in Phase 6 alongside the index work.
  One process note - `npm run test:console` failed once with "'php' is not recognized" because
  PATH was not exported in that shell before Playwright booted its web server. Re-run with the
  export from the Environment section and it passed. The gate is fine; the shell was not.
  Next: P0-07 `document_links`.
- 2026-09-10 * **iter 7** * P0-07 done. `document_links` and
  `App\Domain\Documents\DocumentLinker` - the handoff spine, PLAN.md section 1's "the reference
  number of the document before it". Polymorphic on both ends, because the spine has to span
  every chain: a PO points back at a PR and a tabulated bid, an AP voucher at all three.
  The spine is a **DAG, not a list**, and the guards are what keep it one. `link()` refuses a
  self-edge, the composite unique key refuses a duplicate edge (which would double-count a
  document in every trace, and the P&L is assembled from traces), and a forward reachability
  walk refuses any edge that would close a cycle. That last one earns its cost: `ancestorsOf()`
  is the question the table exists to answer, and in a graph with a cycle that question has no
  answer at all.
  **Phase 0 has no documents yet**, so the linker is exercised against the models that do exist -
  a budget revision genuinely is a predecessor/successor pair and a budget genuinely derives from
  a project. The mechanism is proven; the real PR-to-voucher chain gets linked in Phase 1.
  Two things caught by the checks rather than by me. `use DomainException;` in the test emitted
  "use statement with non-compound name has no effect" - a real PHP warning, deleted rather than
  tolerated. And PHPStan was right that `->id` does not exist on a generic `Model`: the linker
  returns `Collection<int, Model>`, so the tests now use `getKey()`, which is the typed accessor.
  Next: P0-08 `cutoff_calendar` (closes F3).
- 2026-09-10 * **iter 8** * P0-08 done, **F3 closed**. `cutoff_calendars`, `CutoffType` and
  `App\Domain\Cutoffs\CutoffResolver`. The finding was that one calendar cannot answer "is this
  period still open" for three chains closing on different rhythms, so the cutoff TYPE is part of
  the lookup: billing monthly, payroll semi-monthly, OPEX cutting off on day 26 - before the month
  it closes has even finished. A row with a NULL `project_id` is the organization-wide calendar; a
  row naming a project is that project's override, and the override sorts first.
  The distinction the resolver exists to protect: the **document's** date decides which period it
  belongs to, the **posting** moment decides whether that period still accepts entries. An expense
  dated 3 May booked on 27 May is late against a day-26 cutoff even though its own date is early.
  Three controls sit in the database rather than in PHP. A CHECK constraint refuses a period that
  ends before it starts - an inverted period matches no document date at all and would surface
  months later as "no calendar configured". A missing calendar **throws** rather than reading as
  open, because treating absence as permission defeats the control for exactly the periods nobody
  set up. And uniqueness of the default row is enforced over a stored generated column
  `COALESCE(project_id, 0)`, because MySQL treats every NULL in a unique index as distinct and
  would otherwise accept two organization-wide calendars for one period.
  That generated column forced one design change: MySQL refuses a cascading foreign key on a
  column a stored generated column depends on, so `project_id` is RESTRICT. Better rule anyway -
  a project with configured cutoff periods is closed, never deleted out from under its postings.
  PHPStan again caught a type lie: Larastan infers `string` for date columns, so the `?->`
  fallbacks in the resolver were guarding against a null that cannot occur. The model now declares
  the three Carbon properties and the dead nullsafe operators are gone.
  **PLAN.md corrected in the same commit:** section 4 states the convention "snake_case plural,
  Eloquent conventions" and then listed this one table singular. The table follows the stated rule
  and the plan now matches. Next: P0-09 withholding tax foundation (closes F9).
- 2026-09-10 * **iter 9** * P0-09 done, **F9 closed**. `config/withholding.php`, a
  `Blueprint::withholdingColumns()` macro, and `App\Domain\Tax\WithholdingCalculator`. The
  finding was that tax columns must exist from a table's FIRST migration - adding one to a table
  that already carries postings means restating them - so the shape is fixed now and Phase 1 and
  2 adopt it: `withholding_code`, `withholding_rate`, `withholding_amount`,
  `withholding_certificate_reference`. Four documents each declaring "roughly the same" tax
  columns is how a ledger ends up unable to total its own withholding.
  Two deliberate deviations from F9 as written, both worth stating. The **rate** is
  `DECIMAL(9,6)`, not `DECIMAL(18,4)` - a rate is not money, and four places cannot express a rate
  finer than a hundredth of a percent. And a fourth column, `withholding_code`, was added beyond
  the three the finding names: rates change, and without the code a historic posting cannot be
  explained, only recomputed - with today's rate, giving the wrong answer.
  Every operation goes through **bcmath**, never a float. One test proves it on
  12345678901234.5678, which carries 18 significant digits where a double holds about 15; in
  floating point the tail is silently dropped. Rounding is half-up at the fourth decimal, done
  once on a full-precision product rather than on an already-truncated one. An unconfigured code
  **throws** rather than falling back to zero, because under-withholding does not fail at the time
  - it surfaces as a liability when the return is filed.
  **PLACEHOLDER: Part D item 14.** Rates are the Philippine EWT defaults the deck's BIR references
  imply, recorded in DECISIONS-PENDING.md. They are strings, never float literals, so exactness is
  not lost before the calculator sees them. BUILD-LOOP.md calls this the most urgent open decision
  and it still is: correcting rates after the ledger has rows means restating postings.
  PHPStan corrected me twice, both real. `ColumnDefinition` is a `Fluent`, so `->total` works only
  by magic - array access is the defined API. And a `Blueprint` built off a connection that has
  never run a schema operation has no grammar yet, so the test resolves it first.
  Next: P0-10 Gates service.
- 2026-09-10 * **iter 10** * P0-10 done. `App\Domain\Gates\Gatekeeper`, the `Precondition`
  interface and two exceptions - the third of PLAN.md section 3's four cross-cutting services.
  A gate is a named list of preconditions attached to one transition of one document, declared
  once at boot and enforced everywhere that transition can happen. It lives in app/Domain rather
  than in a Filament resource for the reason PLAN.md section 5 gives: a queued job or a CSV
  import never opens a form, so a control that exists only in the UI is not a control.
  Two design decisions here are deliberate and both are about failure modes rather than features.
  **Every failing precondition is reported, not just the first** - a clerk who fixes one blocker
  only to be shown the next, one round trip at a time, is how a one-day PR target becomes a
  one-week one, and the deck tracks that target. **An undeclared transition is refused, not waved
  through**: if an unknown transition simply passed, a typo in a transition name would enforce
  nothing at all, silently, for as long as it took someone to notice. A transition that is
  genuinely ungated is declared with an empty precondition list, which states the position out
  loud instead of leaving it to be inferred from an absence.
  Preconditions are objects with a stable `name()` rather than closures, so a failure can be
  reported, listed in an admin screen and asserted in a test. "The gate failed" is not something
  anybody can act on; "no tabulated bid" is.
  The registry is bound as a **singleton** - gates are declared at boot and read everywhere, so a
  fresh instance per resolution would silently enforce nothing.
  **The registry is empty on purpose.** Phase 0 has no documents to gate; P0-11 registers the
  first real gate (F14, no PR without a signed contract and an opened budget) and Phase 1
  registers the procurement chain's. What is proven here is the mechanism, not any policy.
  Next: P0-11 project-level gate (closes F14).
- 2026-09-10 * **iter 11** * P0-11 done, **F14 closed** - and with it all four of Phase 0's
  schema-level findings (F1, F3, F9, F14). "No PR against a project without a signed contract AND
  an opened budget", registered in the Gatekeeper by a new `GateServiceProvider`, which is now the
  one file where every gate in the system is declared. Six of the eight tests are rejections,
  which is right for a task whose entire value is what it refuses.
  The gate is declared against the **project**, not the requisition. The requisition does not
  exist until Phase 1, and the rule is really about the project anyway - every future document
  that spends project money reuses it unchanged. Phase 1 calls
  `assert($project, 'raise-purchase-requisition')` before creating a PR.
  Both preconditions are scoped to the project under test. There is a test for that specifically,
  because an unscoped `exists()` would let the first signed contract in the database unlock every
  project - a bug that passes every happy-path test ever written.
  **A `contracts` table was created to close this.** That needs stating plainly: no notion of a
  signed contract existed, and F14 cannot be closed without one. PLAN.md section 4 lists
  `contracts` under the **project spine** alongside `projects`, so this is Phase 0 scope rather
  than reaching into Phase 2 - what Phase 2 builds is billing ON the contract, not the contract.
  The table carries exactly the section 4 columns: NOA, NTP, contract sum, retention %, DLP.
  **PLACEHOLDER: Part D item 3.** Retention defaults to 10% (PLAN.md section 5's assumption) and
  the defects liability period to 365 days, which the deck does not state at all. Both are
  per-contract columns rather than constants, so the client overwriting them is a data change and
  not a code change. Recorded in DECISIONS-PENDING.md.
  One process note: `bootstrap/providers.php` imports its provider class names rather than
  listing them fully qualified, so the first edit silently matched nothing and wrote the file back
  unchanged. Caught by printing the file rather than trusting the write. Next: P0-12 approval
  matrix and the Approvals service.
- 2026-09-10 * **iter 12** * P0-12 done. `approval_matrix`, `approvals` and
  `App\Domain\Approvals\ApprovalRouter` - the **last of PLAN.md section 3's four cross-cutting
  services**. Numbering, Gates, Approvals and Posting were to be built first and built once;
  three of the four now exist, with Posting left at P0-13.
  Routing reads the band a tier covers and returns the ordered approver roles and the document set
  that tier requires. Sole source escalates one tier above; at the top tier it stays there, which
  is stated in the code as a decision rather than left to look like an oversight. Every amount
  comparison goes through **bccomp**, never a float - a purchase sitting exactly on a threshold
  approved one tier too low is the precise failure the matrix exists to prevent, and there is a
  boundary test for it at 50000.0000 and 50000.0001.
  Two refusals matter beyond the usual. **Overlapping bands are rejected at definition time**: an
  ambiguous authority matrix is worse than none because it looks authoritative while routing the
  same amount to two levels depending on query order. And **an amount in no band throws** rather
  than falling back to the lowest tier, since a gap in the bands is a configuration error and
  defaulting downward routes a large purchase to the smallest authority.
  Authority is checked in the **service**, not the UI - a user who does not hold the tier's role
  cannot approve at that tier whatever the form offered them. `approvals` is polymorphic because
  section 3 asks for one inbox and not seven, and it stores `approver_role` as a value rather than
  a reference so an approval stays explainable against the authority in force when it was given.
  Tier 1 takes **one** approver, in the tests and in the seeder. PLAN.md section 9 names approval
  fatigue at Tier 1 as a risk and its mitigation is explicit: it stays a single-canvass,
  one-working-day path, so no second signature was added to it.
  The seeder writes through `defineTier()` rather than inserting rows directly, so the overlap
  check runs for the seed exactly as it will for the admin screen. A seeder able to write a matrix
  the service would reject is a seeder that quietly breaks routing.
  **PLACEHOLDER: Part D items 1 and 5**, both now recorded in DECISIONS-PENDING.md. The peso bands
  are entirely the build's invention - the deck shows bands, not amounts, deliberately - and this
  is **B2, the schedule's critical path**. Routing is proven; the thresholds it routes against are
  not agreed. Every approval routing test stays provisional until they are.
  One process note: the test file was too large for a bash heredoc, which failed with an
  unterminated-quote error rather than writing a partial file. Written with the file tool instead.
  Next: P0-13 project_cost_ledger and the Posting service - the last of the four services.
- 2026-09-10 * **iter 13** * P0-13 done. `project_cost_ledger` and
  `App\Domain\Posting\LedgerPoster` - **all four of PLAN.md section 3's cross-cutting services now
  exist**: Numbering, Gates, Approvals, Posting. Built first and built once, as the plan asked.
  This is the table PLAN.md section 1 calls the organising principle - every process ends here.
  Seven of the thirteen tests are rejections.
  **The ledger is append-only at the database, not in PHP.** Two triggers refuse UPDATE and DELETE
  outright and signal SQLSTATE 45000. A model guard throws a readable exception on the common
  path, but it is explicitly not the defence: an importer or a console command goes straight past
  Eloquent, and section 4 calls this table immutable without qualification. Both triggers are
  verified present in information_schema after `migrate:fresh`.
  Corrections are **reversing entries**, which is the answer to "immutable, so how do you fix a
  mistake". The contra row points at the original, both stay visible, and the pair nets to zero -
  what an auditor expects instead of a row that quietly changed value between two readings of the
  same report. A unique index on `reverses_entry_id` refuses a second reversal, because reversing
  a posting twice turns a correction into a credit.
  The project code and cost code are **snapshotted as values**, not left as foreign keys alone.
  There is a test that renames both after posting and asserts the ledger row is unchanged: a
  ledger that restates last year when someone edits a cost code is not an audit trail.
  Every posting consults the cutoff calendar **for its own chain** - F3's cutoff type is an
  argument, so a payroll posting is not judged against the OPEX day-26 date. Cross-organization
  postings are refused: a cost code from another organization would put one company's cost into
  another company's P&L and every row would still look well-formed. Zero-amount postings are
  refused as noise. `totalFor()` sums with bcadd rather than SQL SUM(), so the number the P&L is
  built on is a decimal string end to end.
  PHPStan caught the same class of type lie a third time - Larastan infers `string` for enum-cast
  columns, and the poster feeds `category` and `cutoff_type` straight back into a typed signature
  when reversing. The model now declares both. This is a recurring pattern worth remembering:
  **every cast that is not a plain scalar needs an explicit @property.**
  Next: P0-14 Filament approval-matrix admin - the first UI task in the build.
- 2026-09-10 * **iter 14** * P0-14 done. The approval-matrix admin - **the first screen in the
  build**, and the one that had to prove PLAN.md section 3's architectural rule survives contact
  with Filament. It does: neither page writes an `approval_matrix` row. Both call
  `ApprovalRouter::defineTier()` and translate a domain refusal into a form error, which is the UI
  layer's actual job. Two of the seven tests exist purely for that - an overlapping band is
  rejected on create AND on edit, because a guard that protects the seeder and the test suite
  while the one path a human uses walks past it is not a guard.
  `document_type` and `tier` are disabled on the edit form. Changing which tier a band belongs to
  is redefining the matrix, not editing it, and allowing it would have `updateOrCreate` silently
  create a fifth row instead of moving the fourth.
  **A real deployment bug was found by this task, not by the browser.** Filament grants panel
  access implicitly in `local` and refuses it everywhere else unless the user model says
  otherwise, so every page 403'd under `APP_ENV=testing`. Staging and production would have done
  exactly the same to every user on day one, and the browser gate would never have caught it
  because `artisan serve` runs local. `User` now implements `FilamentUser` with an explicit
  `canAccessPanel()`: every authenticated user may open the panel, because PLAN.md section 2 picks
  self-hosted auth precisely so every foreman and timekeeper is a user. Opening the panel is not
  access to the data - that stays per-resource and, from Phase 6, per-project row-level policy.
  The console gate covers both new pages and passes 4 of 4. Extending it surfaced a **latent flaw
  in the gate's own sign-in helper**: it clicked Sign in before Livewire had booted, so the click
  was silently discarded and the test sat on a correctly-filled login form until timeout - a
  failure that reads exactly like bad credentials and is not. It now waits for Livewire. That bug
  was always there; adding a third page is simply what made it fire.
  Next: P0-15 unified approvals inbox shell.
- 2026-09-10 * **iter 15** * P0-15 done. The unified approvals inbox - PLAN.md section 3's "one
  inbox, not seven". Seven is the default outcome: each chain grows its own pending screen and an
  approver working across procurement and payroll has to remember to check both. `approvals` was
  made polymorphic in P0-12 precisely so this could be one list, and a test proves it by putting
  approvals from two different source documents on the same screen.
  The rule the screen enforces is that an inbox shows YOUR work: scoped to the roles the signed-in
  user actually holds, and to steps still pending. Two of the eight tests are the negative form of
  that - an approval addressed to a role the user lacks is **not on the screen at all**, and a
  decided one leaves the queue. Every row an approver skips past makes the real one easier to miss.
  The scope lives on `getEloquentQuery()` rather than on the table, so the nav badge, any future
  widget and the record resolution actions use all inherit it. A scope applied only to the visible
  table leaves the underlying records addressable by id.
  Both actions call the **domain service**, which re-checks that the signer holds the tier's role.
  The inbox query already filters by role, but a filtered list is a convenience and not an
  authorisation check - the same distinction P0-14 had to make. Returning **requires a reason**,
  with a test that a blank one is refused: the reason is what tells the originator what to fix,
  and in the billing chain it is what stops the same deducted item reappearing next submission.
  Two smaller judgements worth recording. The resource has **no create or edit page** - an
  approval is opened by the Approvals service and closed by a decision, never typed into
  existence, and offering a form would invite exactly the hand-made approval the authority matrix
  exists to prevent. And the queue sorts **oldest first**: newest-first quietly starves the oldest
  item, which is the one already closest to missing the deck's cycle-time target.
  Sole source gets its own column. It is on the screen because it escalated a tier, and the
  approver should know that before signing rather than after.
  **P0-15 is the last build task in Phase 0.** Only P0-16 remains - deploy pipeline and staging
  subdomain - and it is blocked on a decision, not on code: Part D item 12, the ~$17/mo VPS plus
  Ploi/Forge line. The loop can write the pipeline; it cannot provision a server.
- 2026-09-10 * **iter 16** * P0-16 **blocked**, and deliberately not marked done. The half that
  is code is built: `.github/workflows/ci.yml` runs the loop's entire Step 3 list on a clean
  machine against **MySQL 8.4** - not SQLite, because PLAN.md section 2 picks MySQL for
  `SELECT ... FOR UPDATE` on gapless numbering and the ledger's append-only guarantee is a pair of
  MySQL triggers, so a suite green on SQLite would prove neither. It also runs `composer audit`,
  which PLAN.md section 3's security baseline names explicitly. `deploy.sh` pulls, installs
  `--no-dev`, migrates `--force`, warms caches and **restarts queue workers** - without that last
  step the first payroll run after a deploy executes the previous release, and the symptom is
  wrong numbers rather than an error.
  Fourteen tests guard two failures that arrive silently rather than loudly. **CI drifting below
  the loop's check list** - if a check is dropped from the workflow, a test fails, because green
  CI that quietly checks less than it used to is still green. And **a destructive migration
  reaching a server**: the test asserts the fresh, reset and wipe command names appear nowhere in
  `deploy.sh` as literal strings. That caught my own first draft, whose warning comment named
  them; I reworded the comment rather than loosening the test, because a name that never appears
  cannot be uncommented into service later.
  **What is missing is a server, not code.** Part D item 12 - the ~$17/mo VPS plus Ploi or Forge -
  is unanswered, so there is nowhere to deploy to. Marking this done would require claiming the
  Phase 0 exit gate's "on staging, deployed by push" clause passed on infrastructure that does not
  exist, and the Evidence table in this file exists precisely so that cannot happen quietly.
  CI has not run yet either: `build/phase-0` has never been pushed. The workflow triggers on
  `build/**`, so it runs on the first push.
  **Phase 0 is otherwise complete** - 15 of 16 tasks done, all four schema-level findings closed
  (F1, F3, F9, F14), all four cross-cutting services built. `DEPLOY.md` section 2 lists the exact
  steps for once the server exists. The loop stops here: no further iteration can move this
  forward without a human decision.
- 2026-09-10 * **iter 17** * P0-17 done. `purchase_requisitions`, its lines, and
  `App\Domain\Requisitions\RequisitionService` - PLAN.md section 5's **first** control, finally
  enforceable: "No PR without a cost code and confirmed budget availability." The cost-code half
  was settled in P0-05 by a non-nullable foreign key. This is the other half, and it is the harder
  one, because availability is not a column anything can constrain - it is a calculation over
  everything already committed against that cost code.
  **Two tasks were added to Phase 0 to get here** (P0-17, P0-18) rather than opening Phase 1. The
  phase's exit gate is worded around a PR, so a minimal PR is Phase 0's own requirement; the chain
  around it - RFQ, canvass, tabulation, PO - stays Phase 1 and is not started.
  Four of the eleven tests are rejections and three of them are the interesting kind. **Aggregate
  overspend**: two lines that each fit but together do not, which a line-by-line check would have
  waved through - the check sums per cost code before comparing. **An unbudgeted cost code has
  availability of zero, not unlimited**, because reading "no budget line" as "no limit" is how
  overspend enters a project unnoticed. And **a returned requisition stops committing budget**,
  since a rejected document that still consumed its budget would block the corrected one meant to
  replace it. There is a boundary test too: spending a budget's last peso is allowed, one more
  is not.
  The document exercises the whole Phase 0 substrate for the first time rather than in isolation:
  the F14 project gate refuses a project with no signed contract, Numbering issues the reference
  **inside the creating transaction** so a failure takes the number back with it, and submission
  routes through the authority matrix by amount. That last part surfaced something worth noting -
  the tests had to define the matrix themselves, because `submit()` correctly throws
  `NoApprovalTierException` when none exists. A gap in the bands is a configuration error, and the
  service treats it as one rather than routing to the lowest authority.
  Next: P0-18, the Phase 0 exit gate end to end.
- 2026-09-10 * **iter 18** * P0-18 done. **The Phase 0 exit gate passes end to end** - locally.
  A PR is raised against a project that has cleared F14, routed by amount into the two-signature
  band, signed by both approvers in order, and **rejected by the budget check** when the money is
  not there. Six tests, three of them rejections, and the rejections are the point: a gate that
  only demonstrates the happy path demonstrates nothing, since the deck's whole argument is the
  three failure modes this system refuses.
  Two of those rejections are worth calling out. **One signature of two does not approve** - the
  requisition stays `submitted` until every step of its tier has signed, because a tier that
  requires two signatures and acts on one is not a two-tier approval and the authority matrix
  would mean nothing. And **the second identical requisition fails after the first succeeds**,
  which is the subtler half of the budget control: the first document is entirely valid, the
  second is a copy of it, and availability moved underneath it. A check that only looked at the
  budget line would pass both.
  The return path closes the loop: a returned requisition carries its reason, and **frees the
  budget it had claimed** - `RequisitionStatus::Returned` does not commit, so the corrected
  resubmission is not blocked by its own predecessor.
  `approve()` and `returnForRevision()` were added to `RequisitionService` rather than called on
  `ApprovalRouter` directly, because deciding a step and deciding a *document* are different
  questions - the router knows a signature landed, only the requisition knows whether that was the
  last one outstanding.
  **What is NOT claimed:** the gate reads *"end to end, on staging, deployed by push"*. Everything
  except that final clause is demonstrated. The staging clause needs a server - Part D item 12,
  still unanswered - so P0-16 stays `blocked` and Phase 0 stays open. Marking the phase done on
  the strength of a local suite would be exactly the kind of claim the Evidence table exists to
  prevent.
- 2026-09-10 * **iter 19** * **Phase 1 opened**, and P1-01 done. The entry conditions are
  recorded in the Phase 1 task table rather than glossed: the Phase 0 exit gate passed *locally*
  but not on staging, and B2 is unanswered so the deck's bands are **explicitly accepted as
  placeholders** - which is the plan's own second option, and P1-17 is the re-test it requires so
  the acceptance cannot be quietly forgotten.
  P1-01 closes **F11**. `vendors`, `vendor_accreditations`, and `App\Domain\Vendors\VendorService`.
  Status is an enum carrying a reason, a clearing user and a timestamp, because "suspended until
  cleared by management" is a sentence about accountability and a boolean records none of it -
  not who suspended, not why, not who lifted it.
  **Eligibility is derived on read, never stored.** A stored `is_expired` flag is only as true as
  the last scheduled job that ran, and the day that job silently fails is the day an expired
  vendor receives an RFQ. Two tests pin the rule that matters: a **suspended vendor is ineligible
  even with a live certificate** - a certificate that outranked a suspension would make suspending
  a vendor change nothing about what they can be sent - and a vendor with **no accreditation at
  all is ineligible**, because absence is not permission. There is a boundary test on the twelve-
  month anniversary itself, since somebody will stand on that exact day.
  Accreditations **append rather than overwrite**, so the question an auditor actually asks -
  "was this vendor accredited on the day that PO was raised?" - stays answerable years later.
  Removal is terminal: `clearSuspension()` refuses a removed vendor, and `accredit()` will not
  quietly restore one through the side door either.
  **Bank details are encrypted at rest in the first migration that creates them**, per PLAN.md
  section 3, with a test that reads the raw column and asserts the ciphertext differs from the
  value. Columns are `text`, not `string` - ciphertext is far longer than what it hides.
  PLACEHOLDER: Part D item 17 - which role may clear a suspension is unanswered; the clearing user
  is recorded, and who is *permitted* becomes a permission check when the client answers.
  Next: P1-02 vendor validation visits and bonds (F15).
- 2026-09-10 * **iter 20** * P1-02 done, **F15 closed**. `vendor_validation_visits` and
  `vendor_bonds` - two records the deck implies but never names, and the finding's whole point is
  that **they expire on their own clocks**.
  The test that carries the finding asserts a vendor can be currently accredited AND hold a lapsed
  performance bond at the same time. That combination is exactly what gets a subcontract awarded
  against no cover, and it is invisible to any design that reads vendor standing as one status.
  Bonds are **typed** rather than counted: a surety bond is not a performance bond, they cover
  different exposures, and a test proves holding one does not satisfy a check for the other.
  Accepting either in place of the other would defeat the point of having asked for a specific one.
  A CHECK constraint refuses a bond expiring before it takes effect - such a row covers nothing
  and would read as merely lapsed rather than as the data-entry error it actually is.
  Visits keep their full history rather than overwriting. A vendor that failed one visit and
  passed the next has a record worth reading; overwriting keeps only the flattering half.
  `PassedWithFindings` exists as its own verdict because the middle case is the common one, and
  collapsing it into either neighbour loses the reason anyone bothered to visit.
  Both gates these records feed are still to come: expired accreditation blocks an RFQ (P1-03),
  expired bond blocks a subcontract award (P1-07). What exists now is the evidence they consult.
  Next: P1-03 RFQs, where the accreditation gate finally fires.
- 2026-09-10 * **iter 21** * P1-03 done. `rfqs`, `rfq_recipients` and
  `App\Domain\Procurement\RfqService`. **Two of PLAN.md section 5's controls fire here**, and
  where each is checked was the design decision worth making.
  **Eligibility is checked when a recipient is ADDED, not when the RFQ is issued.** Checking only
  at issue lets an expired vendor sit on the draft looking invited, and whoever assembled the list
  finds out at the last possible moment. Refusing at the point the mistake is made is the entire
  value of the rule being in software rather than on a checklist. Three tests cover the ways a
  vendor can be ineligible while looking perfectly ordinary in a list: **expired**, **suspended**,
  and **never accredited at all**.
  **The three-quote minimum is checked at ISSUE, not at award.** Sending to two and hoping a third
  turns up is how a canvass ends up needing a sole-source justification written after the fact, to
  explain a decision already made. Sole source is the documented exception and is declared when
  the RFQ is *opened* - a deliberate decision rather than an accident of having found one supplier.
  An RFQ cannot exist without an **approved** requisition behind it, enforced by a non-nullable FK
  plus a status check: an RFQ with no PR is a purchase nobody asked for, and vendors are being
  invited to quote on it.
  **The handoff spine carries its first real pair of documents.** P0-07 built `document_links`
  against budgets because no documents existed yet; the RFQ now links back to its requisition, and
  a test walks the edge. Every later document in the chain traces back through here.
  One test-hygiene note: `PurchaseRequisitionFactory` writes its number directly rather than
  calling the Numbering service. A fixture must not consume a real sequence number, or the gapless
  guarantee becomes an artefact of test ordering.
  Next: P1-04 quotes and bid tabulation.
- 2026-09-10 * **iter 22** * P1-03 hardened. A background security review of the previous commit
  found four issues; all four were **real**, and the first was serious enough to have voided the
  control it claimed to enforce.
  **The service comment said sole source "is declared when the RFQ is opened". Nothing enforced
  that.** `sole_source` was mass-assignable, so `$rfq->update(['sole_source' => true])` on a draft
  dodged the three-quote minimum completely - open normally, flip the flag, issue to one vendor -
  and the resulting document read as though the rule had never applied to it. An invariant
  asserted in prose and enforced nowhere is worse than no invariant, because the comment stops
  anyone looking.
  The other three were the same shape. **Gate parity**: eligibility was checked in `invite()`, but
  `$rfq->recipients()->create([...])` reached the relation directly and skipped it - exactly the
  failure PLAN.md section 5 describes, since a queued job or importer never calls the service.
  **State machine**: `status` was writable directly, and `issue()` had no check that it was
  issuing a *draft*, so an RFQ could be issued twice or revived after cancellation. The recipient
  list could also be extended after issue, which makes the three-quote check describe a list that
  no longer exists.
  Fixed at the **model** layer, not only in the service: five columns are refused by an
  `updating` guard unless the write comes through `Rfq::mutate()`, and `RfqRecipient` repeats the
  eligibility and draft checks on `creating`. Guarded rather than removed from `$fillable`,
  because omitting a field makes the write **silently do nothing** and the caller believes it
  succeeded - a refusal nobody can see is barely a refusal.
  Six tests added, each demonstrating one bypass before it was closed. Also added `cancel()`,
  which the state guard implied but did not exist, and it now **stores its reason** - it was
  accepting one and discarding it, and a withdrawn solicitation that cannot say why is the one
  somebody will ask about.
  Worth carrying forward: the same review questions apply to every document still to be built in
  this chain. A control enforced in one method is not a control.
- 2026-09-10 * **iter 23** * P1-03a done. The **Vendors screen** - pulled forward from P1-16 at
  the user's request so the procurement chain is visible while it is built rather than only at the
  end of the phase. P1-16 now covers the remaining documents.
  The P0-14 rule holds and this screen had more ways to break it: **no action writes a status
  column**. Accredit, suspend, clear and remove all call `VendorService`, which is what knows an
  accreditation runs twelve months and what refuses to revive a removed vendor. A form field for
  status would have been a way to type a removed vendor back into good standing.
  Two columns earn their place. "RFQ eligible" is **computed, not stored** - it asks exactly the
  question the RFQ gate asks, so reading it from a column would let the register and the gate
  disagree, and the register is the thing people trust. "Accredited until" reads the latest
  certificate rather than a cached date.
  `clearSuspension` is **hidden** unless the vendor is actually suspended, including on a removed
  one where the service refuses outright. An action that always fails is a bug report waiting to
  be filed.
  Bank details are **write-only**: encrypted at rest per PLAN.md section 3, and a register that
  decrypts and prints them hands back exactly what the encryption was for - on a panel every
  foreman can open. A test asserts the number appears on neither the list nor the edit form.
  **The console gate hit a real limit and it was the gate that was wrong.** Filament's login calls
  `$this->rateLimit(5)`, so the sixth sign-in in a minute is throttled - and the gate signed in
  once per test. The throttle is correct behaviour (PLAN.md section 3 asks for a rate-limited
  login); the gate could simply never have covered more than five screens, and Phase 1 alone adds
  around ten. It now authenticates **once** in a setup project and every test reuses the session,
  which also runs faster and matches how the panel is really used. The login page keeps its own
  signed-out context, since it is the one page that must be seen logged out.
  The demo seeder deliberately seeds **three vendors that are not all healthy**: one current, one
  with a lapsed certificate, one suspended. A seed where everything is fine demonstrates nothing -
  the register's entire value is that it tells them apart.
  Next: P1-04 quotes and bid tabulation.
- 2026-09-10 * **iter 24** * P1-04 done. `quotes`, `quote_lines`, `bid_tabulations` and their two
  services. **The three-quote minimum now applies to who ANSWERED**, not just who was asked -
  P1-03 covered invitations, and inviting three vendors while hearing back from one is not a
  canvass. A comparison of one is a decision that was already made, with the paperwork assembled
  afterwards to describe it.
  **Recommending is deliberately not awarding.** The tabulation picks the lowest responsive quote
  and records what it compared; the award is the tiered approval in P1-06. Keeping them apart is
  what stops "the system chose it" standing in for a signature - the authority matrix exists
  because a person has to own the decision.
  `quotes_compared` is **stored as evidence, not recomputed**. Quotes can be added to an RFQ
  afterwards, so counting today's rows answers a different question than "what was on the table
  when this was decided".
  Eight of fourteen tests are rejections, and the bypass tests were written **alongside** the happy
  path this time rather than waiting for a review: a quote total and a tabulation recommendation
  both refuse a direct `update()`. Also refused - an uninvited vendor's quote (it would sit in the
  canvass looking like part of the comparison), a quote against an unissued RFQ, a second quote
  from the same vendor (one bidder cannot occupy two places in a three-way comparison), and a
  second tabulation of one RFQ.
  **A real inconsistency surfaced, from a test expectation of mine that was simply wrong
  arithmetic.** Chasing the true value showed `bcmul` at scale 4 **truncates**: a line of
  12.5 x 1,499.9999 is exactly 18,749.99875, and truncation silently drops the half - always
  downward. Meanwhile `WithholdingCalculator` had been rounding half up with its own private
  helper. **Two different money rules in one system**, meeting at the three-way match, where a
  centavo nobody can source is worse than either answer. Both now go through
  `App\Domain\Support\Money`, which owns the scale and the rounding rule in one place and handles
  negatives explicitly, since ledger reversals are negative. The withholding tests passed
  unchanged through the refactor.
  Next: P1-05 sole-source justification, approved one level above.
- 2026-09-11 * **iter 25** * P1-05 done. `sole_source_justifications` and `SoleSourceService`.
  P1-03 and P1-04 let a sole-source RFQ skip the three-quote minimum; **this is the price of that
  exemption**, and without it the exemption is just a route around the control - declare sole
  source, invite one vendor, award.
  **The escalation is computed from the AMOUNT, not from whoever raised the document.** A 2m sole
  source sits in tier 3 and is approved at tier 4. Had it been relative to the raiser, the same
  purchase would need different approval depending on who typed it in, and a junior raising
  everything would quietly lower the bar for the whole company. Two tests pin this: one asserts
  the tier-3 amount lands on tier 4's roles, another that a 25,000 purchase escalates 1 to 2.
  `ApprovalRouter::routeFor()` has known how to do this since P0-12 - nothing had used it in anger
  until now, and a capability nobody exercises is a capability nobody has tested.
  Three refusals carry the control. **A blank narrative is rejected**: PLAN.md section 5 says
  "written justification" and means written, since a reason code alone records that somebody chose
  from a dropdown rather than why the canvass was skipped. **One signature of two does not
  approve** - a tier with two approvers acting on one signature is a tier with one approver, and
  the escalation would be cosmetic. And **the tier below cannot sign its own exemption**, which is
  the entire meaning of "one level up".
  `tier` is guarded against direct updates. If it could be edited down afterwards the document
  would still read as though the escalation had happened, which is worse than not escalating.
  Reasons are enumerated rather than free text so the exemptions can be **counted** - a quarterly
  review asking "how many emergency sole sources, and were they emergencies" is what stops the
  exception becoming the habit. Free text records each incident and totals nothing.
  Next: P1-06 purchase orders - no PO without an approved PR and a tabulated bid.
- 2026-09-11 * **iter 26** * P1-06 done. `purchase_orders` + lines and `PurchaseOrderService` -
  PLAN.md section 5's central gate: **"No PO without an approved PR AND a tabulated bid."** Both
  are non-nullable foreign keys, so the AND is a property of the schema rather than a habit the
  service is trusted to remember. Either half alone looks like a complete document set right up
  until somebody asks for the other one.
  **The handoff spine carries its first two-predecessor document.** P0-07 built `document_links`
  as a DAG specifically because a PO points back at both a PR and a tabulation; nothing had
  exercised it until now. Two tests cover it - one asserts both predecessors are present, the
  other walks `ancestorsOf()` and finds the **RFQ the PO never directly references**, two hops
  back. That is the question the spine exists to answer.
  Three checks guard the gap between canvass and award, and each closes a hole that opens with
  time rather than with intent. **The vendor must still be accredited at award** - a canvass run
  in May and awarded in December is exactly when a certificate lapses in between, and there is a
  test that moves the clock forward to prove it. **The award must follow the recommendation**,
  or the abstract of canvass is decorative. And **a sole source needs its justification FULLY
  signed**: the test that matters gives it one of two required signatures, which looks complete on
  a list, and refuses the award anyway - otherwise the exemption is granted by the act of using it.
  **A fixture gap surfaced, and the schema caught it.** `PurchaseRequisitionFactory` created
  requisitions with no lines, so there was no cost code for the PO line to inherit and the
  non-nullable `cost_code_id` refused the insert. That is the column doing its job: PLAN.md section
  5's first control is "no PR without a cost code", so a requisition with no lines is not a lesser
  fixture - it is a document the system is supposed to refuse. The factory now always creates a
  costed line.
  PO lines carry their own cost code, defaulting to the requisition's - the PR is what the budget
  was checked against, so charging the order elsewhere would spend a budget nobody checked.
  `quantity_received` is on the line from the first migration, ready for P1-08's short-delivery
  check, and `Countersigned` is its own status rather than an attachment because F2 gates
  mobilization on it.
  Next: P1-07 subcontracts, where the expired-bond gate from F15 finally fires.
- 2026-09-11 * **iter 27** * P1-07 and P1-08 done. **F15 is now fully closed** - P1-03 blocked an
  expired accreditation from an RFQ, and P1-07 blocks an expired bond from a subcontract award.
  The pairing is the finding's whole point: a subcontract's exposure is performance over months,
  so the document that has to be current is the **performance bond**, not the accreditation. A
  test proves a surety bond does not substitute - it guarantees the bid and says nothing about
  whether the work gets finished. Another proves the bond must **outlast the works**: one lapsing
  mid-contract covers the easy half, since the exposure is largest at the end when the work is
  late and the money is spent.
  P1-08 is receiving, and the interesting case is the ordinary one. **Short deliveries are
  normal** - a truck arrives half full and the site signs for what came. What must not happen is
  the shortfall disappearing, so the ordered quantity is **snapshotted** onto the receipt (the
  document still reads correctly if the order is later revised), the shortfall is **derived at
  receipt** rather than on read (the exit gate asks for it *noted on the DR*, which is a fact
  about the document), and the balance **stays outstanding** so the three-way match has something
  to compare an invoice against. If receiving 400 of 500 closed the line, a full invoice would
  match a partial delivery.
  Over-delivery is refused **cumulatively**. Three 200-unit trucks against a 500-unit order each
  fit on their own; only the running total catches it.
  **The "no receiving report without a PO reference" control is a non-nullable column**, with a
  test that inserts through the query builder to prove the database refuses it rather than the
  service.
  One structural fix: the procurement suites had grown to share fixtures defined in whichever test
  file happened to load first, so a suite only passed when run alongside its neighbours. They now
  live in `tests/Support/ProcurementFixtures.php`, registered through composer's autoload-dev, and
  **go through the real services rather than inserting rows** - a fixture that bypassed the gates
  would let a test pass against a chain the application would have refused to build.
  Next: P1-09 inspections and return-to-vendor.
- 2026-09-11 * **iter 28** * P1-09 and P1-10 done. Inspection, return-to-vendor, and stock.
  **Arriving is not the same as being acceptable.** The gap between receiving and issuance is
  where rejected material lives - on site, signed for, and it must reach neither stock nor a
  payment. The rule carrying it is that **accepted + rejected must equal what arrived**: 480
  accepted and 10 rejected out of 500 leaves ten units that exist physically and in no record,
  which is exactly how material walks off a site. A rejection also needs a reason, because the
  vendor has to be able to answer it and the P1-14 scorecard counts rejections - an unrecorded
  reason is an uncountable one.
  **A stock card is a ledger, not a counter.** The table has no `quantity_on_hand` column at all,
  by the same reasoning as `project_cost_ledger`: a stored balance anything can write is a number
  nobody can explain, and the question a storekeeper is actually asked is not "how many are there"
  but "where did the other forty bags go". Only movements answer that. Receipts are positive,
  issuances negative, and the balance is a sum rather than a difference somebody can get backwards.
  The consequence worth stating: **a physical count does not overwrite the balance.** It posts the
  difference as its own adjustment movement, so the discrepancy stays visible - overwriting would
  make the count self-erasing, which defeats the reason for counting. A count that agrees posts no
  movement at all: still evidence somebody counted, but a zero-quantity row is noise on a card
  that exists to be read.
  **Only inspected, accepted goods enter stock**, and only the accepted quantity - not what
  arrived. `acceptedFor()` returns zero for an uninspected receipt rather than the delivered
  amount, so material cannot bypass the check by simply never being inspected.
  Next: P1-11 three-way match and AP vouchers - "no payment without a three-way match".
- 2026-09-11 * **iter 29** * P1-11 and P1-12 done. The three-way match and the AP voucher -
  PLAN.md section 5's payment control, and with it **F8 closed**. Taken together because they are
  one piece of arithmetic: the advance is recovered AT the voucher, so building the voucher first
  and the offset later would mean shipping a payment that overpays by design for one iteration.
  **The middle document is the one that gets skipped.** Matching a PO against an invoice is easy
  and proves close to nothing: both are documents written by the two parties to the transaction,
  and neither of them says the goods exist. Half a delivery and a full invoice agree with the
  order perfectly. So the ceiling is the **accepted** value - quantity from the inspection, price
  from the purchase order, the two facts neither party can restate alone. Taking the price off the
  invoice would make the match circular, checking the invoice against itself.
  The test that carries the finding is 400 of 500 bags billed at the full 124,500: exactly the PO
  total, so a two-way comparison passes it without complaint. Its pair matters as much - the same
  short delivery billed at 99,600 **matches**, because short is not wrong, it is short, and the
  outstanding 100 stays against the order rather than blocking a correct payment. An uninspected
  receipt has an accepted value of zero and is refused, so goods cannot bypass inspection by
  simply never being inspected.
  **Nothing is written when the figures disagree.** A failed match is a dispute with the supplier,
  not a document in the chain; storing one would leave rows that look like matches to anything
  counting them.
  **F8 - an advance is a real payment with no receipt behind it.** There is deliberately no
  `three_way_match_id` on `vendor_advances`: forcing it through the match means inventing a
  delivery, and the invented delivery is the exact thing a three-way match exists to catch. It is
  tied to the order instead, capped cumulatively at the order value, and recovered at the voucher
  oldest-first. Without that recovery the vendor is paid twice for the same goods and **both**
  payments have a complete document set behind them.
  Order of arithmetic is a decision, not an accident: withholding is computed on the **gross**,
  then the advance comes off. Withholding on the net of an advance under-withholds, and the
  shortfall surfaces months later when the return is filed. 124,500 gross, 2,490 withheld at the
  2% services rate, 25,000 advance recovered, 97,010 paid.
  Two guards are in the database rather than in PHP, because PLAN.md section 5's argument is that
  an importer or a queued job never calls a service. `three_way_match_id` is non-nullable AND
  unique - the first refuses a voucher with no match, the second refuses a second voucher for one
  match, which is a double payment with a valid-looking document set behind each. A CHECK refuses
  a negative net.
  **PHPStan found two real things, and one of them was a bad test.** The test proving a voucher
  needs a match asserted PHP's own `TypeError` on `raise(null, ...)` - which static analysis
  proves at compile time, better than a test can, while proving nothing about the row an importer
  writes. It now goes straight to the query builder, past Eloquent and past the model guard, and
  asserts the **database** refuses it. The other was the F9 macro columns being invisible to
  Larastan: a column created by a schema macro is not inferable, so `ApVoucher` declares them.
  Same recurring lesson as the enum casts in P0-13.
  One naming decision recorded: the match numbers **TWM**, not 3WM. The Numbering service requires
  a bare uppercase prefix starting with a letter, and the prefix is parsed back out of printed
  references across every chain - relaxing a Phase 0 invariant for a cosmetic abbreviation was the
  wrong trade.
  `acceptedGoods()` moved out of `StockTest.php` into the shared fixtures, where the file's own
  docblock says builders belong: a helper living in whichever test file defined it first means a
  suite only passes when run alongside its neighbours.
  Next: P1-13 equipment register (F5).
- 2026-09-11 * **iter 30** * P1-13, P1-14 and P1-15 done. **F5 and F12 closed**, and F11's missing
  trigger built. Three tasks in one iteration at the user's instruction - build the phase out, then
  check it - rather than the loop's usual one.
  **P1-13, the equipment register (F5).** The finding is that fuel, repair and depreciation are
  named inputs to every monthly consolidation and PLAN.md section 4 has no register at all, so the
  input is unbuildable. What makes it buildable is not the table, it is one rule: **a machine is on
  one project at a time.** Assignments overlap only when somebody forgot to release the last one,
  and if that is allowed then every hour of fuel can be charged to either project and the cost per
  unit on both is whatever was picked that morning. So an equipment cost does not name its project
  - it **inherits** it from the assignment live on the date it was incurred, and a cost with no
  such assignment is refused rather than defaulted, because the default in practice is whichever
  project was open in the form.
  Depreciation is **a schedule, not a formula evaluated monthly**. A number recomputed each month
  changes when somebody edits the useful life two years in, and every month already posted then
  disagrees with the report that produced it. The instalments sum to the depreciable base exactly:
  1,000,000 over 60 months is 16,666.6667 rounded and sixty of those is 1,000,000.0020, so the
  final period carries the difference. Two-tenths of a centavo per machine per life is a
  reconciliation nobody can source. Rented machines are refused outright - a rental is an expense
  as incurred, and depreciating it capitalises a cost already paid in full and counts it twice.
  PLACEHOLDER: Part D item 15 - method and life are columns per machine, not constants.
  **P1-14, the scorecard (F12).** The deck contradicts itself: slide 4 names three dimensions,
  slide 5 names four. PHASE-PLAN.md takes slide 5, and the fourth - **document completeness** - is
  exactly the one that gets dropped when a scorecard is built from what is easy to see. Price,
  lateness and rejections are all visible at the gate; a supplier who never sends an invoice or has
  no validation visit on file costs real time and appears nowhere. There is a test for precisely
  that vendor: on time, nothing rejected, priced as tabulated, and scoring below 100 because the
  paperwork is not there.
  **Nothing on the card is typed in.** Every dimension is derived from the chain at rating time. A
  card a buyer can fill in records who likes which supplier, and the suspension rules reading it
  would then be enforcing an opinion. Both cadences the deck specifies are built - rated per PO,
  reviewed quarterly - and the quarterly review is where PLAN.md section 5's rules fire: two late
  deliveries suspends, and there is a test that ONE does not. Five percent exactly does not suspend
  either; the rule says above five, and a threshold that fires on its own boundary suspends a
  vendor who met the standard.
  F11's **warranty claim** trigger landed here too, and it does not wait for the quarterly review:
  the goods have already failed in service, and the vendor should not be collecting new RFQs while
  the company works out whose fault it was. `warranty_claims` records the resolution rather than
  deleting the row, because the finding is specifically about an UNRESOLVED claim.
  **P1-15, material cost in the ledger** - the exit gate's last clause, and the first time the
  procurement chain reaches PLAN.md section 1's organising table. The decision worth recording is
  **when**: cost enters at ISSUANCE, not at purchase. Bought material is inventory, an asset in a
  yard, and charging it when the invoice matched would cost the project for cement nobody has
  opened in a month the works had not reached.
  That made valuation real, so **stock movements now carry a unit cost**: receipts at the price on
  the purchase order, issues at the weighted average frozen onto the movement. Weighted average
  rather than latest price, because valuing an issue at the most recent truck makes cost per unit
  depend on the order the trucks arrived in - 500 at 249 and 100 at 300 is 257.50, and there is a
  test for it. The cost code is the **issue's**, not the order's: the PO says what was bought, the
  issue says which part of the works it went into.
  The posting reference is written **inside the same transaction as the entry**. Stamped first, a
  refused posting would leave an issuance claiming to be in a ledger that never took it - there is
  a test that walks that exact path through a closed cutoff. Material posts against the BILLING
  calendar, not OPEX: it is direct project cost belonging to the month the works consumed it, and
  OPEX's day-26 cutoff governs operating expense, which this is not.
  PHPStan caught the same class of thing a fifth time - `delivery_date` is cast to a date and
  Larastan infers `string` without an explicit @property. That pattern is now five for five.
  Next: P1-16, Filament screens for the rest of the chain.
- 2026-09-11 * **iter 31** * P1-16 done. Nine screens, and the chain is visible end to end for the
  first time: requisitions, RFQs, purchase orders, receiving, stock cards, three-way matches, AP
  vouchers, equipment, scorecards, and the project cost ledger.
  **They are read-only, and that is the design rather than a shortcut.** Every document in this
  chain is created by a service that runs the gates PLAN.md section 5 specifies, and every decision
  is taken in the one inbox from P0-15. A create form on a document screen would be a second way to
  write the row - the one that skips the checks - and a second place approvals happen, which is the
  exact complaint the deck makes about the process today. Two tests assert the refusal twice over:
  `canCreate()` is false AND no create page exists to route to.
  The columns that earn their place are the computed ones. A purchase order's **outstanding**
  quantity is summed from its lines, because that is the number the three-way match reads and a
  cached one would let the screen and the match disagree about the same order. A stock card's
  balance and value are summed from movements, because the card deliberately has no stored balance
  and a screen reading one would be the first place the yard and its record could drift. Equipment
  shows the project it is **currently** on, read from the open assignment.
  **The ledger screen shows the SNAPSHOTTED codes.** P0-13 stores `project_code` and `cost_code` as
  values so a rename does not restate last year, and a screen that joined through the relation would
  undo that on the one page an auditor is most likely to be reading. There is a test that renames a
  cost code after posting and asserts the old code is still what the screen shows.
  **A demo chain seeder now runs the whole cycle through the real services** - project, contract,
  budget, PR, approval routing, RFQ to three accredited vendors, three quotes, tabulation, PO, a
  SHORT delivery of 480 of 500, inspection rejecting 20 of those, stock, issuance, the ledger
  posting, an advance, the match and the voucher. Nothing is inserted directly: a seeder that
  bypassed the gates would demo a chain the application itself would have refused to create. The
  seed is deliberately untidy, because the screens exist to make a short delivery and a rejection
  visible and a seed where everything went well demonstrates none of it.
  Its calendars are seeded three months around **today** rather than at fixed dates. A hard-coded
  May would have failed the moment the month turned, with no visible reason - which is F3's failure
  mode arriving in the seeder instead of in production.
  Two real bugs the console gate caught rather than the browser. The receiving screen returned an
  **enum where the column signature promised a string**, which is a 500 on a page that renders
  perfectly in isolation, and the ledger resource routed to `/admin/ledger/ledgers` because Filament
  derives the slug from resource and model names together - it is now set explicitly.
  Next: P1-17 (the B2 re-test, still blocked on the client) and P1-18, the phase exit gate.
- 2026-09-11 * **iter 32** * P1-17 and P1-18. **The Phase 1 exit gate passes end to end - locally.**
  P1-18 is seven tests, one per clause of the gate, named for the clause so a failure says which
  part broke rather than that the gate failed. Three of the five clauses are **refusals**, which is
  the shape of the whole system: the argument was never that a purchase order can be raised - any
  spreadsheet raises one - it is that the wrong one cannot. An expired-accreditation vendor is
  refused an RFQ at the moment somebody tries to invite it, not at issue. A short delivery is noted
  on the DR and the outstanding 100 stays outstanding. Sole source escalates exactly one tier. And
  material cost reaches `project_cost_ledger`, which closes PLAN.md section 1's circle for the first
  chain.
  Two tests were added beyond the five clauses, deliberately. The gate as written could be satisfied
  by a system that pays for anything once a receipt exists, so one asserts an **uninspected**
  delivery cannot be paid, and the other walks the realistic month: 480 of 500 delivered, 20 of
  those rejected, 460 payable, and the full 124,500 invoice refused where a PO-to-invoice check
  would have passed it.
  **P1-17 is `blocked`, not done, and that is the honest status** - B2 is still unanswered, so there
  are no real numbers to re-test against. What could be built was the re-test itself, and it was.
  The bands moved out of the seeder into `config/approvals.php`, so answering B2 is a configuration
  change rather than a code change - which decides whether the client's answer takes an edit or a
  conversation with a developer. `AuthorityMatrixContractTest` then asserts the properties that must
  hold for ANY bands they send back: contiguous with no gap (a gap throws and the document simply
  cannot be submitted), non-overlapping, open-ended at the top (a ceiling on the highest tier
  creates a purchase nobody can approve), every floor and ceiling routing to the tier that owns it,
  one centavo over a ceiling routing up, sole source escalating exactly one tier and holding at the
  top, tier one keeping its single approver, and each tier requiring at least as many documents as
  the one below. When B2 lands the work is: edit the config, run the file. If the client's numbers
  contain a gap, it says so before a single requisition routes against them.
  One process note, and it is the same lesson as `acceptedGoods()` two iterations ago: the new file
  declared a helper called `router()`, which the Phase 0 approvals suite already had. Pest loads
  every test file into one namespace, so the suite died with a fatal redeclare rather than a failure.
  A generic helper name is a collision waiting for the next suite that wants it.
  **Phase 1 closes with its exit gate green locally and two things carried forward, neither of them
  code**: the *"on staging"* clause (P0-16, still needs a server) and B2. Phase 2 is opened with
  both recorded in its entry conditions, exactly as Phase 1 recorded Phase 0's.
  Next: P2-01, mobilization and permits, gated on a countersigned PO (F2).
- 2026-09-11 * **iter 33** * P2-01 through P2-04. **F2 and F4 closed.**
  **P2-01 turns on one word: countersigned.** Slide 3's obligation says it outright - a purchase
  order can be approved internally and not yet countersigned by the vendor, and the mobilization
  gate tests the second. Approved is the company deciding to buy; countersigned is the supplier
  agreeing to sell. A gate written against `Approved` passes every order the vendor has not
  accepted, and mobilization is expensive, immediate and hard to reverse: the crew has travelled,
  the compound is up, the generator is hired. `countersign()` stores the signatory's NAME, because
  the question in a dispute is who accepted the order and a boolean cannot answer it.
  The gate is declared in `GateServiceProvider` against the ORDER rather than the mobilization -
  the mobilization does not exist when the question is asked, and the fact being tested is a fact
  about the order. That keeps P0-10's rule intact: every gate in the system readable in one file.
  The checklist is created WITH the mobilization from an enum, because a list somebody assembles by
  hand is a list that can be assembled short. `PermitsSecured` is the item with teeth: slide 3 says
  "site setup and permits secured", and a box anybody's pen can reach records intent rather than
  fact - so it reads an actual permit with an actual expiry. `permits` is the **third expiry clock**
  in the system after accreditations and bonds, and it follows both of their rules: validity derived
  on read, renewals appended rather than overwritten.
  **P2-02 and P2-03 close F4**, and they were built together because a schedule without its document
  sets is exactly the honour system the finding objects to. The finding is not that the deck omits
  the documents - slide 6 lists them per milestone, precisely - it is that nothing in the system
  knows what they are, which leaves four of five milestones unenforced. The sets are **per
  milestone**, so satisfying the downpayment's three says nothing about the 50% progress billing's
  three, and there is a test asserting the two lists are not equal.
  Two decisions worth recording. The requirements are **copied onto the milestone** when the
  schedule is built rather than read from config at check time: the requirement in force when a
  contract was signed is the one that contract is held to, and adding a document for next year's
  projects must not silently restate a contract already halfway billed. And the percentages **must
  total 100** - a schedule that does not is a contract under- or over-billed by construction, and it
  surfaces only when the final billing fails to balance, by which time the work is done. Milestone
  amounts are computed from the contract sum rather than stored, so a variation order leaves no
  stale figures, and the last milestone carries the rounding difference - the same rule as the
  depreciation schedule in P1-13.
  **P2-04: verified is a consequence, not a status.** Slide 6 makes the client's signature the
  condition an invoice rests on, so an accomplishment becomes verified when two signatures exist on
  its survey and by no other route. A status anybody could write would make that signature a
  formality applied after the decision. A survey signed by the contractor alone is a measurement,
  not an agreement - there is a test for exactly that, and it is the one that matters.
  The subtle rule is the decrease. Progress normally rises, but a **returned** billing is re-measured
  and the figure can legitimately fall. Refusing that outright would make P2-05's returned branch
  unusable; allowing it silently would let a figure drop with nothing recording why. So it is allowed
  and requires a reason. A client signature from anybody but the named representative is refused,
  because otherwise the representative field is decorative and the signature unattributable.
  Process note, and it is the **third time**: `approvedOrder()` collided with a helper the receiving
  suite already had, and the suite died with a fatal redeclare rather than a failure. Renamed to
  `orderAwaitingCountersignature()`. Generic helper names in Pest files are a standing hazard.
  Next: P2-05, billings and the approved-or-returned branch.
- 2026-09-11 * **iter 34-35** * P2-05 through P2-10. **F9's other half and F13 closed.**
  **P2-05's whole weight is one clause**: a returned billing's deducted lines stay blocked from the
  next submission. Billing the same item twice is not a hypothetical failure, it is the DEFAULT one
  - the QS reworks the submission from the spreadsheet it was copied from, the deducted line is
  still in it, and without a block the client's evaluator is the only control standing in the way.
  So `billing_deduction_blocks` **outlives the billing it came from** and lives at project level,
  where the next submission will meet it. Clearing one is an act with a name and a re-measurement
  note attached, not a side effect of trying again.
  A returned billing **keeps its number**. It is the same document coming back; a fresh number would
  make two documents out of one conversation with the client. And the returned status is styled as a
  state rather than an error on the screen, because PHASE-PLAN.md calls that branch first-class and
  colouring it red teaches the QS that an ordinary re-measurement is something gone wrong.
  Three gates run before a submission is accepted: F4's document set, the verified accomplishment
  actually REACHING the milestone (complete paperwork on 42% does not open a 50% billing), and no
  line the client already deducted.
  **P2-06 lands F9's other half.** P0-09 built withholding for supplier payments, where the company
  withholds from the vendor. Here the direction reverses - the client withholds from us - and what
  arrives in the bank is less than the invoice. That gap is not a shortfall to chase; it is
  creditable withholding, and the certificate proving it is worth exactly the amount withheld. A
  collection recorded without the reference is tax the company has paid and cannot claim back, and
  it is **invisible**, because the invoice reads as settled. Refused.
  Retention is deliberately **not collectible**: the client holds it by agreement until the defects
  liability period ends, so invoicing it would put a receivable nobody intends to pay yet in front
  of the weekly AR sweep - which trains everybody to ignore the sweep.
  **P2-07: retention is a ledger, not a column**, for the same reason the stock card is. What a
  contractor asks is "how much of our money is the client still holding, and from which billings",
  and only movements answer that. Release is **a date, not a decision** - the DLP is contractual,
  and a contract with no completion date has not started one. A missing date is not permission, the
  same rule the cutoff calendar follows.
  **P2-09**: revenue posts at the INVOICE and at the GROSS. A billing is a claim the client has not
  accepted - booking it would recognise income they are about to dispute, which is what the returned
  branch exists for. A collection is cash arriving, which would make the P&L a cash statement. And
  retention is earned-and-withheld rather than unearned: booking net would understate every project
  by 10% until close-out, then produce a sudden unexplained gain.
  **P2-08 closes F13**, and the finding's distinction is the whole task: a report is something
  somebody has to open, an escalation arrives. Three things make it real - a **named recipient**
  (the project manager, because "escalated" with nobody to escalate to is a status change), firing
  **at thirty days** on the boundary rather than whenever the weekly sweep runs, and **not
  repeating**. An escalation that reappears every week until the client pays teaches its reader to
  ignore the inbox, which is worse than never having built it; it fires again only when the invoice
  crosses into a further bucket, because sixty days is a different conversation.
  **P2-10**: six screens, and the demo seeder now runs the billing cycle as well - including a
  **returned** billing with its deduction and the block behind it, a part-collected invoice, and an
  AR escalation addressed to the project manager. A demo where every billing sails through
  demonstrates none of what Phase 2 built.
  One seeder note worth recording: the demo's calendars are deliberately left open, with a comment
  saying so. A faithful ten-day cutoff would make the seed fail on documents dated a month back -
  on a control that is working exactly as intended. The control has its own tests; the seeder exists
  to give the screens something to show.
  Process note, and it is the **fourth time**: `orderAwaitingCountersignature()` was declared in the
  mobilization suite and wanted by the billing screens suite. It now lives in the shared fixtures,
  where cross-suite builders belong. `tests/Support/BillingFixtures.php` was created for the same
  reason and registered in composer's autoload alongside the procurement one.
  Next: P2-11, the Phase 2 exit gate.
- 2026-09-11 * **iter 36** * P2-11. **The Phase 2 exit gate passes end to end - locally.** Eight
  tests, and the central one walks the whole arc in a single test rather than in three: May's
  billing goes in with two lines, the client deducts one and returns it, and JUNE's resubmission is
  refused when the deducted line comes back and accepted when it does not.
  That arc is the phase. Returning a billing is easy - any status column does it. What the gate
  actually asks for is the last six words, *"without the deducted line reappearing"*, and that is a
  claim about the NEXT submission: a different document, written next month, by somebody working
  from the same spreadsheet the deducted line is still in. A deduction recorded only on the billing
  it came from would be a note on a dead document.
  The returned billing **keeps its number** through the round trip, and the deduction reason is
  still readable on it afterwards - which is what the QS re-measures against.
  Two tests beyond the four clauses, both deliberate. A gate that only demonstrates the happy path
  demonstrates nothing, so one asserts a **returned billing cannot be invoiced** - the branch has to
  actually stop the money - and the other asserts the receivable is **escalated to the project
  manager at thirty days**, because the gate could otherwise be satisfied by a system that raises
  receivables nobody ever chases.
  **Phase 2 closes with five findings shut in it** - F2, F4, F9's other half, F13 - and the same
  two things carried forward as Phase 1: the *"on staging"* clause and, now, B3 rather than B2.
  **Phase 3 is opened with a harder entry condition than Phase 2 had.** B2 was unanswered bands; B3
  is payroll rules. An approval band routed wrongly is embarrassing; a payroll rule applied wrongly
  is somebody's pay, and they notice on the day. P3-13 is the re-test, built the way P1-17 was, so
  the SOP's rules replace the deck's defaults as a configuration change rather than a rewrite. The
  phase also carries PLAN.md section 3's hardest schema rule: government numbers and salary rates
  **encrypted at rest in the first migration that creates them**, not added afterwards.
  Next: P3-01, the employee 201 file.
- 2026-09-11 * **iter 37** * P3-01 through P3-03. The 201 file, manpower requisitions, and the
  contract gate.
  **This is the table PLAN.md section 3's encryption rule was written for.** A vendor's bank details
  are commercially sensitive; an employee's SSS number, TIN and salary rate are personal, and the
  people they belong to did not choose to give them to a system - they gave them to an employer.
  "In the FIRST migration that creates them" is not fussiness: adding encryption later leaves the
  plaintext in every backup, replica and dump taken in the meantime, and no migration reaches those.
  Two tests read the **raw columns** rather than the model. Reading back through the cast proves
  only that the cast works; reading the column proves the database does not hold the plaintext. The
  rate is encrypted too, and for a sharper reason than the government numbers: it is the one field
  that tells a reader what everybody in the company is worth relative to everybody else. That costs
  the ability to SUM the column in SQL, which is a fair trade - nothing should be totalling salaries
  with a query.
  **A rate is a history, not a column.** Payroll never asks what somebody earns; it asks what they
  were earning during the period being computed. A mutable `daily_rate` answers only for today,
  which means a raise granted in May silently restates April's payroll the moment anyone re-runs or
  corrects it - and late corrections are exactly when this matters. `rateOn()` is the only way
  payroll reads one, and it returns **null** rather than zero when nothing was in force: a run that
  treated a missing rate as zero would pay somebody nothing and look like it worked.
  Resignation and termination are separate states rather than one `inactive` flag - final pay,
  re-hireability and the reason an auditor asks about all differ, the same argument F11 made about
  suspending a vendor.
  **P3-03 is slide 7's flattest rule: "employment contract signed BEFORE first shift."** That is a
  gate, not a filing instruction. Somebody who works an unsigned shift has still worked it: the
  company owes them for it and owes the statutory contributions on it, while holding nothing that
  records the terms. Every incentive on a busy site pushes toward letting them start and doing the
  paperwork on Friday, which is precisely why the rule has to live somewhere Friday cannot reach.
  Two consequences. **A signature cannot be backdated past the start date** - signing on the 5th a
  contract that began on the 1st IS the paperwork being caught up on, and recording it as compliant
  would make the gate a formality. And **signing is what starts the rate history**: the contract is
  where the rate was agreed, so typing it separately afterwards is how the 201 file and the signed
  terms come to disagree - and payroll reads the 201 file.
  The gate is declared against the EMPLOYEE in `GateServiceProvider`, for the same reason F2's is
  declared against the purchase order: the timelog does not exist when the question is asked. It
  needed a date as well as a subject, and the Gatekeeper's contract takes one subject - carried as
  a non-column `assertion_date` on the model rather than widening the interface for one caller.
  Next: P3-04 and P3-05, timelogs and the held-day mechanic.
- 2026-09-11 * **iter 38** * P3-04 and P3-05. **The held day** - the mechanic PHASE-PLAN.md says
  Phase 3 exists to settle.
  Slide 7's rule is *"no timelog, no pay - unvalidated days are held and paid in the next cutoff
  once the site certifies them"*, and the tempting reading is that an uncertified day is simply not
  paid. **But the person worked it.** Dropping the day balances the run and leaves them short a
  fortnight with nothing on record saying why, and the correction arrives weeks later as somebody
  typing an adjustment line with no evidence behind it. `Held` keeps the day, keeps its hours, and
  records WHICH cutoff it fell out of - so the next payslip can say "carried from 1-15 May" rather
  than showing an extra day nobody can explain.
  `paid_in_period_end` is the other half, and it guards the failure this mechanic would introduce if
  built carelessly: a day paid once in its own period and again as a carry-over. There is a test for
  exactly that.
  **Held is not a way of paying it later regardless.** A held day becomes payable by being validated
  late, not by being held - "no timelog, no pay" survives the mechanic, and there is a test that an
  uncertified held day stays unpaid. `Rejected` is a third outcome and deliberately not a shade of
  held: a day that never happened must not carry forward waiting to be certified.
  **The import reports what it could not take.** A biometrics export that silently skips unparseable
  rows is a payroll run missing days nobody knows about, and the person who notices is the one who
  was not paid. Rejected rows come back with their **line numbers**, which is what makes the report
  actionable rather than merely alarming. A row for somebody with no signed contract is rejected and
  reported rather than discarded - the shift happened either way, and slide 7's contract gate is
  reached from the timekeeping chain for the first time here.
  PHPStan caught something worth recording, and it was right in an interesting way: the import's
  docblock declared its row keys as guaranteed, which is a claim about a **CSV somebody else's
  device wrote**. Widened to `array<string, mixed>`, which makes the defensive reads load-bearing
  rather than looking like dead code.
  `tests/Support/HrisFixtures.php` created and registered - the fifth time this build has moved a
  helper out of whichever suite declared it first. Payroll makes it worse than the other chains did,
  because almost nothing can be tested in isolation: a DTR line needs a signed contract, which needs
  an employee, and the contract gate refuses the shortcut on purpose.
  Next: P3-06 and P3-07, OT authorities and the queued payroll run.
- 2026-09-11 * **iter 39** * P3-06 through P3-08. Overtime authorities, the payroll run, and the
  statutory deductions - **the first time this build computes somebody's pay.**
  **P3-06, "approved in writing."** The failure it prevents is ordinary rather than fraudulent: a
  punch at 21:40 proves somebody was on site, not that anybody asked them to be. Paying every hour
  past eight at the premium makes the time clock the approval, and the premium is a quarter on top.
  **Payable premium hours are the SMALLER of worked and authorised**, every day, and both halves are
  tested - worked-but-unauthorised hours stay on the DTR unpaid at premium, and an authority for four
  hours on a day somebody left at five pays nothing, because an authority is a permission and not a
  timesheet. Night hours come from the **punches**, never from the authority. The written reference
  is required at approval; a verbal okay recorded by whoever clicked is not "in writing".
  One judgement call beyond the slide, recorded as one: the requester cannot approve their own
  authority. The deck names the timekeeper and the site engineer as different owners.
  **P3-07/08, the run.** Four rules, each preventing a specific way people end up paid wrongly. A run
  belongs to **exactly one cutoff** - the 3rd to the 18th straddles two, and every overlapping day is
  payable by both. **Validated days only**, with closing the cutoff holding what the site did not
  certify. **Each day at the rate in force on THAT day** - a raise on the 10th pays the 8th at the old
  rate however late the run is computed, and a carried day keeps its own date's rate. And **a missing
  rate is an exception on the run, not a zero line**, because a zero line looks like a run that
  worked. Deductions exceeding a very short cutoff's pay are an exception too: carrying a negative
  net is an SOP decision this build will not invent.
  The held-day mechanic from P3-05 meets money here, and the test walks it: May 5 uncertified at the
  first cutoff, one day paid; certified late; the second run pays it **flagged as carried**, so the
  payslip can say so.
  **The strongest guard is a database key, not the service.** `payroll_line_days` holds a unique key
  on the DTR day, so a day can sit on one payroll line, ever. Double pay is what every payroll defect
  eventually becomes, so it gets a constraint no future code path can forget to check. There is a
  test that bypasses the service and asserts the database refuses.
  **Pay amounts are encrypted at rest too** - deliberately past the letter of PLAN.md section 3,
  which names rates. A net pay figure beside the days worked IS the rate. Run totals across the whole
  organization stay plain, because a total over everybody reveals nobody's rate.
  **The statutory tables are the build's approximation of published Philippine schedules, not an
  accountant's**, and `config/payroll.php` says so in as many words. What the tests vouch for is the
  shape every such schedule shares and the three places payroll routines get it wrong: the SSS salary
  credit is ROUNDED to its bracket rather than truncated (truncating under-contributes on half of
  every bracket, and the shortfall is the employee's benefit); every base has a floor AND a ceiling;
  and withholding taxes the amount OVER a bracket floor. Plus one rule of ORDER - contributions come
  off first and tax is computed on what is left, because taxing gross withholds on money the employee
  never received. The worked example: 12,000 gross, 1,000 in contributions, 87.45 tax, 10,912.55 net.
  `ComputePayrollRun` is the **build's first queued job**. It carries the run's id rather than the
  model, so a retry reloads current state, and the computation is one transaction so a failed job
  leaves the run exactly as queued.
  **DECISIONS-PENDING.md corrected in the same commit.** It had not been updated since Phase 0 - items
  2, 15 and 17 and three unnumbered placeholders from Phases 1 and 2 were logged here but never
  added there, which the loop's rules require. Now 14 entries, including items 4 and 9 for payroll.
  Next: P3-09, F10 - the payroll register variance gate.
- 2026-09-11 * **iter 40** * P3-09. **F10 closed** - the payroll register variance gate, the second
  independent variance gate PHASE-PLAN.md asks for beside the OPEX close.
  Two words in the finding carry it, and each has its own test.
  **"Per project."** A run-level comparison hides exactly the movement the review exists to catch.
  The central test builds it: last cutoff two days on project A and two on B; this cutoff three on A
  and one on B. Four days both times - **the run total does not move by a centavo**, and a
  total-only check approves it. But A's labour is up 1,200 and B's down 1,200, and both figures go
  into each project's cost per unit. The test asserts the totals are equal, then asserts approval is
  still refused - and that explaining A alone does not unblock it, because explaining one project is
  not explaining the register.
  **"Explained."** Not acknowledged, not ticked: a written reason per project, recorded against the
  run before approval. A blank one is refused. So is an explanation for a project whose cost did not
  move - otherwise "explained" could be satisfied by pasting a sentence onto every row. So is one
  written after approval, because explanations belong to the review; written afterwards they justify
  a decision rather than inform it. The figures each explanation was written against are
  snapshotted, so it stays readable beside the numbers it explained.
  The gate **blocks** rather than warns, through the Gatekeeper like every other gate. A variance
  review that can be skipped gets skipped on the busy cutoffs - which are the cutoffs with the
  variances.
  Comparison is against the **last run actually computed**. Draft and cancelled runs are skipped
  rather than compared against, and skipping a cutoff does not escape the gate: the next run is simply
  compared to the one before the gap. An organization's first run has nothing to vary against and
  sets the baseline. A project new to the register is a variance from zero and needs a sentence; its
  tolerance is a share of the PREVIOUS figure, so a brand-new project has no allowance at all.
  PLACEHOLDER: B3 - the tolerance defaults to **zero**, which is the deck's literal rule that every
  variance is explained. A variance sitting exactly on a configured tolerance is within it, and there
  is a boundary test at 50%.
  **Approval is now where a day becomes PAID**, not computation. A computed run can still be refused,
  and a day stamped paid by a register that never went out is a day the employee is never paid for.
  One considered exception to the line-level encryption, recorded as one: variance explanations store
  per-PROJECT labour totals in plain DECIMAL. They are the same figures P3-11 posts to the ledger in
  the clear. On a one-worker project a project total does reveal that worker's pay - equally true of
  the ledger, and a reason for access control on both rather than for encrypting one.
  Process note: the PayrollService edit failed first time because the tool layer collapsed `\\` to
  `\`, and Python reads `\N` as a unicode escape - a hard error, where the same collapse elsewhere
  only produced warnings. The edit script was rewritten to a file with raw strings, and the gate
  registration that had only warned was **grepped to confirm** rather than assumed correct.
  Next: P3-10, payslips and the disbursement file.
- 2026-09-11 * **iter 41** * P3-10. Payslips and the disbursement file.
  **Slide 7's "or" is the whole task**: "disbursement by bank upload OR cash payout with
  acknowledgment." The two methods prove payment in completely different ways, and one `paid`
  boolean over both would lose the only evidence the cash half has.
  A **bank upload** is evidenced by the file that went and the reference that came back. Preparing
  and transmitting it releases the money; nobody signs anything, and demanding a signature as well
  would make every bank payroll wait on paperwork that does not exist. A **cash payout** is
  evidenced by a signature and nothing else - so it is **not released until somebody acknowledges
  it**, because cash with no acknowledgment is indistinguishable from cash still in the drawer.
  That is the test that matters, and its pair: two employees, one signs, and the register stays
  **approved rather than released**. Closing a cutoff that still owes somebody money is how the
  person who has not been paid stops appearing on anybody's list.
  **Both files go to the PRIVATE disk.** A disbursement file is every employee's name, account
  number and net pay in one document; on the public disk it is one guessed URL away from being
  everybody's payslip. There is a test asserting the path, and `storage/app/private` was confirmed
  git-ignored before anything was written to it.
  A bank upload missing an account number is **refused and named**, not skipped - a bank file
  quietly one line short is somebody not paid, discovered when they say so.
  **The payslip labels a carried day as carried.** This is where P3-05's held-day mechanic finally
  reaches the person it was built for: without the label the payslip shows a day from a month they
  were already paid for, and they would be right to query it. The payslip also renders only from an
  **approved** register - issued off a computed one it states a figure the variance review might
  still change, and the employee has it in writing before anybody decided it was right.
  `summaryFor()` builds every figure the template prints, so the numbers can be asserted without
  parsing a PDF - and so a second template renders the same numbers rather than its own reading of
  them. dompdf runs with **remote fetching disabled**: a payslip template must not be able to reach
  the network while rendering somebody's pay.
  **Two of my own test expectations were wrong, and the code was right.** The batch total and the
  bank file line were written against GROSS; a disbursement moves NET. 2,400 gross less 298 of
  contributions (the SSS and PhilHealth floors bite at this level, and there is no tax below the
  first bracket) is 2,102. Corrected, with the arithmetic written into the test so the next reader
  does not have to rederive it.
  PHPStan caught a `->not` chained after `->toContain()` - invisible to static analysis through
  Pest's mixin, the same class of problem P0-05 hit with the custom money expectation. Split into
  its own statement rather than suppressed.
  **New dependency: dompdf 3.1.6.** `composer audit` reports no advisories; the extensions it needs
  (dom, mbstring, gd, xml, fileinfo) were confirmed present before installing.
  Next: P3-11, labour cost posting to the ledger.
- 2026-09-11 * **iter 42** * P3-11. **Labour cost reaches the ledger** - the third chain to arrive
  at PLAN.md section 1's organising table, after material in P1-15 and revenue in P2-09.
  Three decisions, and the obvious implementation gets each of them wrong.
  **Per PROJECT, not per employee.** A ledger row per person would put every salary into the P&L,
  readable by anybody who can read the ledger - and the whole of Phase 3 has been careful to encrypt
  exactly that. The ledger is a cost record, not a payroll register: it needs cost by project, which
  is what `payroll_line_days` already carries. The test puts two employees on one site and asserts
  **one row**, with neither person's pay recoverable from it.
  **At GROSS, not net.** Labour cost to the project is what the work cost the company, not what the
  employee took home. Posting net would understate every project by the statutory deductions and
  leave those deductions accounted nowhere at all. The test asserts gross 2,400 posts while net is
  2,102.
  **On the PAYROLL calendar.** F3's whole point - payroll closes semi-monthly, billing monthly, OPEX
  on day 26, and "nothing books after cutoff" means nothing until you say WHICH cutoff. The test
  proves it by closing only the payroll calendar and leaving billing wide open, then asserting the
  posting is still refused.
  One employee across two sites in a cutoff is **two rows**, each carrying only the days worked
  there. Charging the whole cutoff to one project is how a project's cost per unit becomes fiction.
  Posting is from an **approved** register, never a computed one: the variance review can still
  change it, and the ledger is append-only - a posting made from a computed register would need a
  reversing entry rather than an edit. And `posted_at` is stamped inside the posting transaction,
  the same discipline as the material and revenue posters, so a refused posting cannot leave a
  register claiming to be in a ledger that never took it.
  No ledger entry id on the run, unlike the material and revenue rows: a payroll run posts one row
  per project, so there is no single entry to point at. The link back is `source_document_type` and
  `source_document_id` on the ledger rows themselves.
  The test fixtures needed a `costedProject()` helper - a project with no cost code cannot be posted
  against at all, which is a real refusal the poster makes rather than a fixture inconvenience. The
  billing fixtures already do the same for revenue.
  PLACEHOLDER: a DTR records WHERE somebody worked, not which part of the works their hours belong
  to, so labour takes the project's first cost code unless a caller names one. Mapping labour to a
  WBS code is a chart-of-accounts question the client has not answered.
  **Phase 3 is 11 of 14 done.** Remaining: P3-12 (screens), P3-13 (the B3 re-test, still blocked on
  the SOP) and P3-14 (the exit gate).
  Next: P3-12, Filament screens for the payroll chain.
- 2026-09-11 * **iter 43** * P3-12. Five screens over the payroll chain, and the demo seeder now runs
  a full semi-monthly cutoff.
  **This is the chain where a screen leaking is worst**, and the tests that matter are the ones
  asserting what is NOT rendered. Phase 3 encrypted government numbers, salary rates and every
  per-person pay figure at rest; a register that decrypts and prints them hands back exactly what
  the encryption was for, on a panel every foreman can open. So the employee register shows
  **"Rate on file" and "Bank details" as computed booleans** - the state a payroll clerk needs,
  without the value being readable - and there are two tests, one in Pest and one in the console
  gate, asserting the seeded TIN, SSS number, account number and daily rate appear nowhere in the
  rendered page.
  The payroll register shows **totals only, never a line**. A run total across everybody reveals
  nobody's pay, which is why `payroll_runs` keeps its totals in plain DECIMAL while every
  `payroll_lines` amount is encrypted; a per-employee figure on the list would undo that in the
  easiest place to read.
  Three computed columns earn their place. **Unexplained** counts the projects still blocking
  approval under F10 - a register that cannot be approved and does not say why is one somebody
  reports as broken. **Payable** on the overtime screen is the smaller of authorised and worked, so
  an authority for four hours on a day somebody left at five reads 4 authorised and **0 payable**
  rather than suggesting four are owed. **Awaiting signature** counts unacknowledged cash payments,
  because a batch total alone makes a half-paid batch look settled.
  `Held` is styled as a state rather than an error on the DTR screen, with a dedicated "Held,
  awaiting certification" filter. A held day is somebody's unpaid work waiting on a signature -
  ordinary on a busy site, and the one thing it must not be is invisible.
  The disbursement file path is **shown rather than linked**: the file is on the private disk, and a
  download link from a shared list is how every employee's account number leaves the building.
  **The seeder needed payroll calendars**, and adding them made F3 concrete: billing and OPEX get one
  period a month, payroll gets **two**. The demo cutoff is deliberately untidy like the other two
  chains - two workers, ten days, one **held day** carried out of the cutoff, and an overtime
  authority capped at two of the four hours worked. 24,525 gross reaches the ledger as labour.
  One test-hygiene note: the DTR screen test first asserted on an ISO date, and the column formats
  it. Corrected to assert the rendered format - a test that assumes the storage format passes only
  by accident.
  **Phase 3 is 12 of 14 done.** Remaining: P3-13 (the B3 re-test, still blocked on the SOP) and
  P3-14 (the exit gate).
  Next: P3-13 and P3-14.
- 2026-09-11 * **iter 44** * P3-13 and P3-14. **The Phase 3 exit gate passes end to end - locally**,
  and Phase 3 closes with **F10 closed** and the held-day mechanic proven across two cutoffs.
  **P3-14 is seven tests, and the central one walks both cutoffs in a single test** rather than
  three. May 1-15 pays one day and holds another; the site certifies the held day late; May 16-31
  pays it, flagged as carried, and a third register finds nothing left to pay. That arc is the
  phase: holding a day is easy - any status column does it - and what the gate actually asks is
  that the day is **paid later, once, at its own rate**. The test asserts the day still carries its
  hours and its date while held, that the second register pays it, and that
  `paid_in_period_end` stops a third from paying it again.
  Three tests beyond the gate's wording, all deliberate. A shift worked **before the contract was
  signed** is refused at import - the gate could otherwise be satisfied by a system that pays
  anybody who punches in. A register whose **variance is unexplained** cannot be approved (F10). And
  a **cash payroll is not released until every payment is signed for** - "one cutoff runs" could be
  read as ending at the register, and slide 7 ends it at the money.
  **P3-13 is `blocked`, not done, and that is the honest status.** B3 has not been issued, so there
  are no SOP tables to re-test against. What could be built was the re-test itself, the same shape
  as P1-17 for the approval bands: `PayrollRulesContractTest` runs **108 assertions** against the
  properties ANY schedule must satisfy - floors and ceilings that both bite, a salary credit
  **rounded rather than truncated** (truncating under-contributes on half of every bracket, and the
  shortfall is the employee's benefit), contiguous ascending brackets starting at zero, tax on the
  **excess over** a floor so landing exactly on it costs nothing, a **monotonic** schedule where
  earning more never takes home less, contributions before tax, and deductions never exceeding
  gross. When B3 lands the work is: edit `config/payroll.php`, run the file. If the SOP's tables
  contain a gap, an inversion or a cliff, this says so before a single payslip is printed.
  **Phase 3 closes with three things carried forward, none of them code**: the *"on staging"* clause
  (P0-16, fourth phase running), B3, and now Part D items 11 and 13. Item 11 is different in kind
  from the placeholders this build has been absorbing - **BIR CAS registration is a regulatory
  filing about the system itself**, and it cannot be defaulted. Phase 4 will build the consolidation
  regardless; what it cannot do is make the output a registered book of account.
  Next: P4-01, expense capture with a mandatory cost code.
- 2026-09-11 * **iter 45** * P4-01. Expense capture, and slide 8's hardest clause.
  **"An expense with no receipt, no cost code, or no budget line is not booked - returned to the
  site the same day, and cannot be charged to the project later."** The first three are refusals in
  front of capture, and the cost code is the strongest because it is a **non-nullable foreign key**
  rather than a check: no importer, console command or queued job can create an uncoded expense.
  The fourth clause is the task. PHASE-PLAN.md reads "cannot be charged later" as barred from **that
  period**, and the period is the whole nuance. A returned expense is usually a missing receipt or
  an uncoded line, not fiction - so the site corrects it and books it against the NEXT period, which
  is legitimate. Barring it forever would mean the company never books a real cost because somebody
  forgot a receipt in May. What must not happen is the same receipt reappearing in a period whose
  numbers have already been reported. Two tests, one for each half.
  The bar is keyed on the **receipt, not the amount**. Two different bills for the same amount in
  one month is ordinary; keying on the amount would block the second one. And a bar raised in error
  is cleared by a named person with a note - the same shape as P2-05's billing deduction block.
  **The database found a real design tension, and it was right to.** The unique key "one receipt per
  project per period" collided with clearing a bar: the RETURNED row still occupied the slot, so a
  cleared bar left the corrected expense permanently unbookable - the opposite of what clearing is
  for. Slide 8 says a returned expense is "not booked", so it should not hold the slot. Resolved
  with a **stored generated column** that is NULL while the expense is returned, unique over that:
  MySQL treats every NULL in a unique index as distinct, so any number of returned rows may share a
  receipt while only one live one may. The same technique P0-08 used to make a nullable project_id
  behave in a unique index.
  An unbudgeted cost code has availability of **zero, not unlimited** - the rule P0-17 established
  for requisitions, applied to OPEX. A cost code from another organization is refused outright.
  **PHASE-PLAN.md correction, same commit.** My own P4-03 row had the calendar as "27 review · 28
  coding · 29 variance · 30 close"; slide 8 says **27 coding · 28 validation · 29 consolidation · 30
  budget review**. The deck wins - the task table now matches it.
  Next: P4-02, cash advances and liquidations.
- 2026-09-11 * **iter 46** * P4-02 through P4-04. **F17 closed** - the cross-chain write
  PHASE-PLAN.md says quietly never ships.
  **P4-02: the unliquidated balance is derived, never stored.** The same rule the stock card
  follows, and here it has teeth: a stored `outstanding` column is only as true as the last routine
  that wrote it, and the day-26 job reads that number to decide what to deduct from somebody's pay.
  **A liquidation points at a real captured expense**, which goes through `ExpenseService` and
  therefore through slide 8's own three refusals - a liquidation with a bare figure behind it is an
  advance written off by assertion. The exception is a **cash return**, which has no expense because
  nothing was bought: somebody drew 10,000, spent 8,400 and handed back 1,600, and forcing that into
  an expense would mean inventing one for the change in their pocket. A CHECK constraint allows
  exactly one of the two forms per row - neither accounts for nothing, both would count a peso twice.
  **P4-03: a state machine, not a set of dates.** PHASE-PLAN.md is explicit that the scheduler drives
  it, and the difference is that a stage is ENTERED by a transition somebody performed. Reading the
  day off the clock would mean a month whose scheduler did not run on the 26th silently behaves as
  though it had - and the cutoff is the stage that stops expenses booking into a reported period.
  Stages advance one at a time and never backwards: skipping capture to consolidation builds a
  consolidation nobody coded or validated, and going backwards changes numbers somebody has already
  reported. Every transition records who made it, because a calendar nobody signed cannot answer
  "who closed May" - the first question asked when a May number turns out to be wrong.
  **P4-04 is F17, and two things make it real rather than notional.** The sweep is **part of the
  cutoff transition** rather than a separate job, so a cut-off period cannot exist without it having
  run. And the charge is a **`payroll_deductions` row the next payroll run reads**, not a note
  somebody is expected to act on - which is exactly the difference the finding warns about.
  The failure a scheduled job introduces if it is careless is being run twice: a retry, a second
  scheduler, somebody clicking twice, and the employee is deducted the same money again - they find
  out on payday. Guarded by a **unique key on `cash_advance_id`**, with a test that runs May's and
  June's cutoffs in sequence and asserts one deduction. The sweep also looks back to **the period's
  own day 26**, not today, so a job rerun in July sweeps May as May was rather than pulling in
  advances that belong to June.
  Receipts arriving after the sweep are refused as liquidations - the money has already left OPEX,
  and that is a payroll correction rather than a liquidation.
  `tests/Support/OpexFixtures.php` created and registered - the **sixth** time this build has moved
  a helper out of whichever suite declared it first. OPEX made it unavoidable: a cash advance needs
  an employee, a project, a budget line and a cost code before it can be released at all.
  Next: P4-05 and P4-06, budget versus actual and the variance gate on the close.
- 2026-09-11 * **iter 47** * P4-05 through P4-07. **Overhead reaches the ledger, and with it all four
  chains have arrived** at PLAN.md section 1's organising table - material (P1-15), revenue (P2-09),
  labour (P3-11) and now overhead. Every process the deck describes now ends where the plan says it
  should.
  **The day-30 gate is the OPEX twin of F10**, and deliberately a separate gate rather than a shared
  one: the chains close on different calendars - F3's whole finding - and answer to different
  reviewers. Two words carry it, the same two that carried F10.
  **"Above 10%"**, so ten exactly is inside the threshold. A gate that fires on its own boundary
  blocks a close that met the standard, and the person asked to explain it has nothing to say beyond
  "it is exactly ten". There are two tests on that line: 110,000 against a 100,000 budget closes,
  112,000 does not - and 12% is the phase exit gate's own figure.
  **An underspend blocks as well as an overspend.** A cost code 40% under is as interesting as one
  40% over: either the work is not being done or the budget was wrong, and both need a sentence. A
  gate that only looked upward would miss half of what a budget review is for.
  A **returned expense does not count toward actual** - slide 8 says it is "not booked", and counting
  it would put a cost the company refused into the comparison the close rests on.
  One arithmetic case worth recording: a budget cut to zero after spending began cannot be divided
  into. Reporting null would read as "no variance" on the most interesting row on the report, so the
  variance is computed against one centavo instead and comes back enormous, which is the truth.
  **P4-07 posts one row per COST CODE, not per expense.** A month of site utilities is forty
  receipts; forty ledger rows would make the P&L unreadable and turn the budget reconciliation into a
  summing exercise. The receipts stay individually visible on `expenses` - the ledger carries the
  cost, the expense carries the evidence. On the **OPEX calendar**, which cuts off on day 26 before
  the month it closes has even finished, with a test that leaves billing wide open and asserts the
  posting is still refused.
  Each expense is stamped posted inside the same transaction as the entry, so a refused posting
  cannot leave an expense claiming to be in a ledger that never took it - the discipline every poster
  in this build follows.
  The close gate is asserted **through the Gatekeeper** like every other gate, and only on the
  transition out of budget review: a period still in capture has nothing to explain yet, and refusing
  to cut it off would stop the month.
  Next: P4-08 and P4-09, the consolidation, project P&L and F16's cash requirement.
- 2026-09-11 * **iter 48** * P4-08 and P4-09. **F16 closed** - the cash requirement, and the
  consolidation that produces it.
  **The P&L is assembled by SUMMING, not by classifying.** P0-13 categorised every posting when it
  was written - five categories, decided at posting time - precisely so this report can add up
  rather than decide what things are. A consolidation that re-classified would be a second opinion
  about numbers already reported, and the two would eventually disagree. It reads the **ledger**,
  never the source documents, for the same reason: a report built from expenses and payroll lines
  directly would be a second path to the same figures.
  Reversing entries need no special handling and that is the point: P0-13 corrects by writing a
  contra row rather than editing, so a corrected cost is two rows that net to zero when summed.
  There is a test that reverses a posting and asserts the category comes back to zero.
  A project with **no revenue yet** reports a zero margin rather than dividing by nothing. Every
  project looks like that in its first months - cost accruing, nothing billed - and breaking the
  report there would break it for the projects most worth watching.
  **F16 is the finding that a P&L is not a cash figure**, and the tests are built around exactly
  that gap. An **approved purchase order** is money the company has promised and has not spent: it
  appears in no ledger category at all until the goods arrive, and it is precisely what a treasurer
  needs to know about next month. Its pair asserts the order **stops** counting once delivered -
  otherwise the money is set aside twice, once as a commitment and again as a payable.
  Five figures, none of them from the P&L: committed orders, open payables, an approved register not
  yet released, expected collections, and retention held. **Collections are counted** because a
  figure with only outflows would tell a treasurer to borrow money the client is about to send. And
  **retention is reported but excluded** from collections - the client holds it until the defects
  liability period ends, and counting it would have somebody expecting money nobody intends to send
  for a year.
  **Two of my own test setups were wrong, and the code was right in both.** The committed-cash test
  used a fixture that fully DELIVERS the goods, so nothing was outstanding - the setup contradicted
  the test's own name, and it now uses an approved undelivered order. The retention test assumed
  invoicing withholds automatically; P2-07 deliberately made `withhold()` its own step, so the
  retention ledger was empty. Both corrected, with the reason written into the test.
  `retention()` and `revenue()` moved into `BillingFixtures` - the **seventh** helper extraction, and
  the first one caused by a suite in a different chain wanting a billing helper.
  **Phase 4 is 9 of 11 done.** Remaining: P4-10 (screens) and P4-11 (the exit gate).
  Next: P4-10, Filament screens for the OPEX chain.
- 2026-09-11 * **iter 49** * P4-10. Four resources, one page, and an OPEX month in the demo.
  **The project P&L is a PAGE, not a resource**, and that is the decision worth recording. It is not
  a list of records - giving it a resource would imply there is a `profit_and_loss` table somebody
  could edit. It is a view of the ledger, summed at read time, with no stored totals and no snapshot
  rows: a cached consolidation would be a second set of numbers to reconcile, which is precisely the
  problem the ledger was introduced to end.
  The **cash requirement sits beside the P&L rather than inside it**, two tables per project. They
  answer different questions and the deck lists them separately; merging them would suggest the cash
  figure is a P&L line, which is the exact confusion F16 exists to correct. Retention is shown
  **greyed and outside the net**, because the client holds it until the defects liability period
  ends.
  Three computed columns carry the resources. **Outstanding** on an advance is asked of the same
  service the day-26 sweep asks, so the screen and the sweep cannot disagree about what somebody
  owes - the record has no such column at all. **Unexplained** on the calendar counts the cost codes
  blocking the close, because a month that will not close and does not say why is one somebody
  reports as broken. And the stage badge prints its **slide-8 day** - "Budget review (day 30)" -
  because that is how the people running the calendar actually talk about it.
  `Returned` is styled as a state rather than an error on the expenses screen, the same judgement the
  billing screen makes: most returns are a missing receipt, and red teaches a site clerk that an
  ordinary correction is something gone wrong. The **return reason** is on the row, because it is
  what the site corrects against.
  **The demo month is deliberately left AT BUDGET REVIEW, blocked.** 71,100 captured against a
  100,000 line is a 29% underspend, above the 10% threshold, so the close will not proceed until
  somebody explains it - and the calendar screen shows exactly that. There is also a returned expense
  barred from its period and an advance swept to payroll at the cutoff. A month that closed cleanly
  would demonstrate none of what Phase 4 built.
  Two console selectors needed tightening rather than the pages needing fixing: "Charged to payroll"
  is both a column header and a badge, and the advance number appears inside the deduction's reason
  sentence as well as in its own column. Both scoped, with the reason written in - a selector that
  matches two things proves only that one of them exists.
  `periodAtReview()`, `budgetActual()` and `overheadPoster()` moved into `OpexFixtures` - the eighth
  helper extraction.
  **Phase 4 is 10 of 11 done.** Only P4-11, the exit gate, remains.
  Next: P4-11.
- 2026-09-11 * **iter 50** * P4-11. **The Phase 4 exit gate passes end to end - locally**, and Phase
  4 closes with **F16 and F17 shut** and all four chains posting to the ledger.
  Ten gate tests. Two of the three clauses are refusals, which by now is the shape every exit gate in
  this build has taken: the argument was never that a month can be closed - any calendar closes a
  month - it is that a month with an unexplained variance in it **cannot** be.
  The first clause has a subtlety the wording hides, and both halves are tested. **"Permanently
  barred from the period" is not "permanently barred"**: the corrected expense belongs in the NEXT
  period, and a system that refused it forever would mean never booking a real cost because somebody
  forgot a receipt in May. The uncoded-expense refusal is asserted at the **database**, going past
  the service entirely - PLAN.md section 5's argument is that a control living only in a service is
  not a control.
  Two tests beyond the gate's wording, both deliberate. A **returned expense is not posted** to the
  ledger - the gate could otherwise be satisfied by a system that bars an expense from capture and
  then books it anyway, and slide 8 says a returned expense is "not booked". And the **day-26 sweep
  fires** during the close: a month-end that left advanced money unaccounted for would satisfy the
  wording and miss F17 completely.
  One test walks the whole month in order - capture, the cutoff that sweeps advances, coding,
  validation, consolidation, the budget review that **holds** until the variance is explained, and
  reporting. That is "one month-end close", and the hold is the part worth having.
  `consolidation()` moved into `OpexFixtures` - the **ninth** helper extraction. Nine is enough of a
  pattern to state plainly: in this build a test helper wanted by two suites belongs in
  `tests/Support`, and the second suite finds out by fatal redeclare rather than by failure.
  **Phase 5 opens carrying four things, none of them code.** The staging clause (fifth phase
  running), B3, Part D items 11 and 13, and now **B4** - the paper audit PHASE-PLAN.md wants
  overlapping this phase so findings land while the close-out flows are still soft. B4 is unlike the
  others: an audit is people reading paper, and the build cannot do it on their behalf.
  Next: P5-01, substantial completion and the punchlist.
- 2026-09-12 * **iter 51** * P5-01. Slide 9's first two steps, and the sentence that shapes the phase:
  **"close-out is a checklist with named clearers per line, not a status flag."** That is a schema
  instruction, not a UI preference, and it decided every column on the table.
  **`punchlist_items` has no status column at all.** `cleared_at` is the truth and the name beside it
  is what the close-out report is made of. An `is_cleared` boolean would be a second place for the
  same fact to live - and the one that survives an import.
  The obligation is enforced in the **database**, not only in the service: a CHECK constraint makes a
  cleared item carry a time, a name AND a note, or not be cleared. Probed by name against the live
  schema rather than inferred from a passing test - `punchlist_items_clearance_is_signed`,
  `punchlist_items_responsibility_is_attributed` and `punchlists_closure_is_signed` each refused the
  row they exist to refuse. `cleared_by_user_id` is **restrictOnDelete**, not nullOnDelete: a
  certificate whose signature evaporated when somebody tidied up an account is exactly the anonymous
  paperwork slide 9 forbids.
  **The subcontract is named on the item in the migration that creates it**, not bolted on in P5-02.
  Slide 9 computes back-charges AT punchlist clearing, so an item attributed to a subcontractor is
  the thing F6 charges against; adding the column a task later would mean rows already existed that
  named nobody. A second CHECK makes the attribution exact both ways - a subcontractor item names a
  subcontract, an own-forces item names none, because charging our own defect back would double a
  cost the project already carries through labour and materials.
  **An empty punchlist is NOT a cleared one**, and this is the judgement most likely to have gone the
  other way. "No open items" is true of a list nobody has walked, so closing is its own named act
  with a note and `isCleared()` reads `closed_at` rather than counting rows. Same shape as every
  absence-is-not-permission decision in this build.
  **Clearing is not editing.** An item already cleared is refused rather than overwritten: the second
  signature would replace the first, and the report would name the wrong person for work they never
  checked.
  Two refusals guard the ordering slide 9 states. A punchlist cannot exist without a certificate
  (non-nullable FK - the only form of that ordering an importer cannot walk around), and substantial
  completion cannot be certified twice for one project (unique key - a second certificate gives the
  defects liability period two start dates and retention two release dates).
  `subcontracts()` and `bondedSubcon()` moved into `ProcurementFixtures`, and `CloseoutFixtures` was
  written as a shared file on the FIRST close-out task - the tenth extraction, and the first done
  before the fatal redeclare rather than after it.
  PHPStan caught a real one: Larastan infers a cast column as `string`, so **every**
  `$project->phase === ProjectPhase::...` in the build was reading as a comparison that can never be
  true. Declared on the model.
  Next: P5-02, back-charges - **F6**.
- 2026-09-12 * **iter 52** * P5-02. **F6 closed.** Back-charges have a table, they are ledger
  postings, and final billing can read them.
  The finding gives two verbs and they land in different places: **computed** at punchlist clearing,
  **applied** at final billing. Computed is enforced - a charge is refused against an OPEN item,
  because until the defect is put right the cost of putting it right is an estimate, and the ledger
  cannot tell an estimate from money spent.
  **One leg is posted, not two, and this is the decision worth defending.** The cost of the remedy is
  real and already incurred, so it goes to the ledger as Subcontract category. The RECOVERY does not.
  Booking both legs at raise time would credit the project with money nobody has collected and net
  the exposure to zero on the day it arose - and what is actually recoverable depends on how much of
  the subcontract is still unpaid at settlement. So the cost is posted and the payable is derived,
  which is how every balance in this build works.
  That leaves the number worth having. A defect can cost more to put right than is left owing under
  the subcontract; `unrecoveredFrom()` says how much. F6 calls it "increase project cost" - 125,000
  charged against an 80,000 subcontract leaves the payable at zero and **45,000 the project eats**,
  visible now rather than discovered at final billing. A system that netted it out would hide a real
  loss until somebody tried to collect it.
  `unpricedItems()` is slide 9's structural obligation as a query: "final billing depends on
  back-charges being computed first, which makes **step 2 a hard predecessor of step 4 across two
  different chains**." A cleared subcontractor defect nobody has priced is an open question, and
  P5-05 is entitled to refuse to invoice over it.
  Three guards past the service. `punchlist_item_id` is UNIQUE - one defect, one charge, or the
  subcontractor is billed twice for the same handrail. `back_charges_amount_is_positive` refuses a
  negative charge, which is a payment to the subcontractor wearing the wrong document; probed by name
  against the live schema. And the LedgerPoster's cross-organization guard is reached through this
  door like any other.
  The charge is dated from the **clearing**, not from today - the remedy was carried out then, and
  the period it belongs to is the period the works consumed it. Same rule the expense capture uses.
  Next: P5-03, the warranty register - **F7**.
- 2026-09-13 * **iter 53** * P5-03. **F7 closed.** The warranty register exists, and the claim that
  was already firing F11's suspension trigger now names the promise it is made under.
  Half of F7 arrived a phase early. `warranty_claims` was built in P1-14 because the suspension
  trigger needed something to fire on, and it has been firing since - against a vendor and an order.
  What it never had was the certificate, so a claim could name the supplier but not the promise the
  supplier was being held to. This task builds `warranties` and joins the two.
  **Coverage is a period, and the operative date is the failure.** Not the filing date - and that is
  the decision the suite is built around. A compressor that died the day before expiry is covered
  however long the claim took to be typed up, so `failed_on` is a column on the claim and it is what
  coverage is tested against. A register that checked the filing date would deny that claim quietly,
  and in the vendor's favour. A failure dated forward is refused: it is a forecast, and it would
  suspend a vendor on one.
  **The claim goes through the same door as before.** `WarrantyService::claim()` calls
  `ScorecardService::raiseWarrantyClaim()` rather than writing its own row, so a claim raised from
  the register suspends the vendor exactly as F11 requires. A second path that recorded a claim
  without suspending would have disarmed the trigger for precisely the well-documented claims - the
  ones with a certificate behind them.
  **The certificate stays OPTIONAL on the claim,** which is worth stating because the tidier schema
  is the wrong one. Goods whose warranty nobody collected still fail; refusing that claim would lose
  the trigger along with the paperwork. So `warranty_id` is nullable and the gap is reported instead -
  `uncoveredSubcontracts()` names the subcontracts with no certificate on file, the counterpart of
  P5-02's `unpricedItems()`, and P5-04's turnover pack can refuse to close over it.
  Two guards past the service, both probed by name against the live schema.
  `warranties_coverage_period_is_ordered` refuses an inverted period - one that is in force on no
  date at all, so every claim under it is denied and the register reads as though the certificate had
  never been collected: a bug that looks like paperwork. And
  `warranty_claims_warranty_vendor_foreign` is a **composite** key on (warranty_id, vendor_id)
  referencing warranties(id, vendor_id), which needed a second unique index on the parent to exist.
  A single-column reference would let a claim name one vendor and point at another vendor's
  certificate, and the suspension would land on the wrong company. MySQL skips the check when
  warranty_id is NULL, which is exactly the un-certificated case above.
  The certificate reference is unique **per vendor**, not globally. It is the supplier's own
  numbering, and two suppliers both issuing "0001" is ordinary - a register unique on the reference
  alone would refuse the second one. There is a test for that specifically.
  One process note on the loop's own discipline: the vendor-mismatch check inside
  `raiseWarrantyClaim()` was written before its test. It was disabled, the test run and watched to
  fail with a raw foreign-key violation rather than a readable message, and then restored - which is
  the point of that test, since a database error is not something a clerk can act on.
  Next: P5-04, the turnover pack and acceptance.
- 2026-09-13 * **iter 54** * P5-04 done. The turnover pack and the client's acceptance of it -
  slide 9 step 3, sitting where the slide puts it: after punchlist clearing, before final billing.
  **The pack has two kinds of line, and that is the whole task.** A FILED line - as-built drawings,
  the O&M manuals, the certificate of completion - is satisfied by somebody putting a reference on
  file and signing for it: slide 9's "named clearer per line" one level down from the checklist. A
  DERIVED line - warranty certificates, permits - is answered by the register and **cannot be filed
  by hand at all**. It reads P5-03's warranty register and P2-01's permit register.
  That distinction is the point. A pack that let a clerk tick "warranty certificates: collected"
  while subcontracts sit in the register with no certificate against them is the honour system F4
  objected to, rebuilt one phase later - and rebuilt at the moment in a project when everybody wants
  it closed. So the tick is not offered. `missingFor()` **names the subcontracts** nobody chased,
  because "warranty certificates outstanding" is not something a PMO can act on.
  A project that let no subcontracts owes no certificates, so that line passes on a project with
  none. What it refuses is the project that let works and never collected the paper.
  The permit line asks only whether ANY permit is on file - an excavation permit that expired when
  the excavation finished is still a document the client is owed. Which permits a turnover
  specifically requires is the same question `PermitService::hasAnyValid()` already records as
  unanswered by the deck, so the line refuses only the case the build can be sure about: nothing on
  file at all. Recorded in `config/closeout.php` as a placeholder, not as a decision.
  **Acceptance is refused while the punchlist is open**, and refused outright on a project where no
  punchlist was ever issued - the absence-is-not-permission rule P5-01 turned on. A client accepting
  a site with defects outstanding accepts the defects with it.
  Three CHECK constraints, probed by name against the live schema.
  `turnover_packs_acceptance_is_signed` makes acceptance carry a time, an internal signatory AND the
  client representative's name, or none of the three - a date with nobody against it is the
  anonymous paperwork slide 9 forbids. `turnover_pack_items_filing_is_signed` does the same per line.
  And `turnover_pack_items_register_lines_are_not_filed` puts the filed/derived distinction in the
  database, so it does not rest on one service method an importer never calls.
  The label, the required flag and the source are COPIED onto the row at assembly rather than read
  back from config. The pack records what was asked for at turnover; editing the template a year
  later must not restate a pack the client already signed.
  Two methods were caught by the loop's own discipline this iteration - the register-line constraint
  and `filingReport()` were both written before their tests. Each was disabled, the test watched to
  fail for the right reason, then restored. `filingReport()` is the per-pack shape of slide 9's
  close-out report, which P5-08 assembles project-wide.
  Next: P5-05, final billing applying deductions and back-charges before the invoice.
- 2026-09-13 * **iter 55** * P5-05 done. Slide 9 step 4: "applies deductions and back-charges
  BEFORE the invoice."
  **Before the invoice is the whole control, not a sequencing preference.** An invoice raised at the
  gross and corrected afterwards has already been sent - the client holds a document saying one
  number, the ledger says another, and the difference is chased by whoever notices first. So the
  deductions are applied to the billing, `billings.deductions_amount` is a stored column, and the
  invoice is computed FROM the deducted net rather than recomputing anything at invoice time. A sum
  re-derived from live back-charges would silently restate an invoice already in the client's hands.
  **The hard predecessor is enforced, not documented.** PHASE-PLAN.md: "final billing depends on
  back-charges being computed first, which makes step 2 a hard predecessor of step 4 across two
  different chains." `raise()` refuses over any cleared subcontractor defect P5-02 has not priced,
  and NAMES the item - "back-charges outstanding" is not something a QS can act on. Turnover
  acceptance is required too, which is slide 9's step 3 before step 4.
  **Back-charge deductions are derived and cannot be typed.** The amount was computed at punchlist
  clearing; a hand-entered one is a second opinion about a number that already exists, and the two
  will differ. `final_billing_deductions_back_charge_is_typed` puts that in the database in both
  directions - a row labelled back_charge naming none is refused, and so is a liquidated-damages row
  pointing at a back-charge, which would deduct one charge twice under two names.
  The gate is a **Gatekeeper precondition**, `final-billing-deductions-applied`, declared in
  `GateServiceProvider` beside every other control and asserted by `CollectionService::invoice()`.
  In the ordinary path it passes silently, because `raise()` already applied everything. It exists
  for the path that is not ordinary - an importer, a console command, a screen built later - and the
  test proves it by deleting the deduction row at the database, which is what those leave behind.
  Two decisions worth recording. **Deductions exceeding the bill are refused**, not floored: the
  client owes nothing and the company owes them, which is a settlement, and an invoice for a
  negative amount goes into the AR sweep as something to chase. And **applyDeductions() is refused
  once the invoice exists** - the correction at that point is a credit note, which this build does
  not have, and pretending otherwise would change our number without changing theirs.
  PHPStan caught a real one: `PunchlistService` was injected and never read. Removed rather than
  given a use - turnover acceptance already requires the punchlist closed, and a second copy of one
  rule is the copy that goes stale.
  Next: P5-06, demobilization and clearance.
- 2026-09-13 * **iter 56** * P5-06 done. Slide 9's third closing panel, "People and assets":
  clearance and final pay, equipment and IT asset return. The scorecards on that same panel are
  P5-09.
  **Two kinds of line again, and for a different reason than P5-04's.** A PERSON is a named act -
  each individual gets a row somebody signs, because slide 9's rule is a named clearer per line and
  final pay is the line that matters most to the person on it. PLANT and MONEY are derived: what is
  still on site is a question the equipment register already answers, and an unliquidated advance is
  one P4-02's register answers. Neither is tickable here. A demobilization that let somebody assert
  "all plant returned" while assignments sat open would be a softer copy of a fact that already
  exists - and the soft copy is the one filled in on the last day of a job.
  **The cross-chain rule this task exists for: a person holding an unliquidated advance cannot be
  cleared for final pay.** Final pay is the last moment the company has any leverage to recover it,
  and F17's day-26 sweep charges the NEXT payroll - of which, for somebody leaving, there may not be
  one. The refusal names the advance and what is outstanding on it.
  The clearance list is built from any daily time record on the project, validated or not. Somebody
  whose last day was never certified still holds tools and an ID card, and filtering to validated
  days is how a clearance list quietly shortens itself.
  `outstandingFor()` returns every reason at once - uncleared people by employee number and name,
  plant by code and the date it arrived, advances by number and amount. One round trip per blocker
  is how the last week of a project becomes three.
  Two CHECK constraints, probed by name: `demobilizations_completion_is_signed` and
  `demobilization_clearances_clearance_is_signed`. No status column on either table; the timestamp
  IS the clearance and the name beside it is what the report is made of.
  One tidy-up. `equipment()`, `depreciation()` and `ownedExcavator()` were declared inside
  `EquipmentRegisterTest.php`, which works only while that file happens to be loaded - running the
  close-out suite alone would have fataled on an undefined function. Moved to
  `tests/Support/EquipmentFixtures.php` and added to composer's autoload-dev files, which is the
  same rule the close-out fixtures header already states.
  Next: P5-07, retention release at DLP end - the exit gate turns on it.
- 2026-09-13 * **iter 57** * P5-07 done. Retention released at the end of the defects liability
  period, and the collection that closes the project - slide 9's closing rule and the clause the
  **phase exit gate** turns on.
  **Released is not collected, and that distinction is most of the task.** P2-07 built the retention
  ledger and a `release()` that moves the balance. Read on its own it conflates two events weeks
  apart in practice: the client AGREEING the period has run, and the money arriving. Slide 9 says
  "a project stays open in the books until retention is COLLECTED", so a project closed on the first
  of those is closed on a promise.
  So `retention_releases` is its own document. `claim()` raises it at DLP end - the request the
  client pays against - and the ledger movement is written when the money lands. The balance then
  means what it says: what the client is still holding. There is a test for exactly that, asserting
  the balance is unmoved after a claim and zero after a collection.
  **The movement goes THROUGH `RetentionService::release()`, not around it.** That service owns the
  ledger, and a second writer would be a second set of rules about what may move the balance - the
  two diverge on the first change to either. A test reads the entry back off the ledger to prove the
  path.
  Two claims open at once are refused; a second claim after the first is collected is ordinary, and
  a test proves partial release still works. The collection needs a reference - the receipt the
  money arrived against, without which nothing reconciles to a bank line - and cannot be dated
  before the claim.
  **`ProjectCloseoutService` is the other half.** A project cannot close while retention is held OR
  a claim is raised and unpaid, while any invoice is uncollected, or while the demobilization is
  unfinished. Plant on a site nobody is watching is plant that goes missing. Every reason is
  reported at once, and a test asserts all three arrive together rather than one per round trip.
  Who closed a project and when goes to the ACTIVITY LOG rather than to columns on `projects`.
  P5-08 is the document that assembles the close-out report, and a pair of columns here would be the
  second place that fact lives.
  Two CHECK constraints probed by name: `retention_releases_amount_is_positive` and
  `retention_releases_collection_is_signed` - collected, or not collected, never half of it.
  PHPStan caught two real ones in the fixtures: a return type naming an unimported `Billing`, and
  `fresh()` being nullable where the suites destructure without checking. Swapped for `refresh()`,
  which returns static.
  Next: P5-08, the close-out checklist - named clearer and timestamp per line.
- 2026-09-13 * **iter 58** * P5-08 done. The close-out checklist - slide 9's structural obligation,
  the one PHASE-PLAN.md puts at the head of the whole phase: "close-out is a checklist with named
  clearers per line, **not a status flag**." Eleven lines across the slide's three panels: documents
  to close, financial close, people and assets.
  **There is no status column anywhere on either table.** That is the rule written as schema rather
  than as convention. `cleared_at` IS the clearance, the name beside it is what the report is made
  of, and `close_out_checklist_items_clearance_is_signed` refuses every other combination - a line
  carrying a time with nobody against it is exactly the flag slide 9 forbids, wearing a timestamp.
  **The design decision worth defending: a line whose fact the build already holds cannot be signed
  while that fact is false.** Slide 9's rule stops a status flag. It does not stop a signature that
  is not true, and on the last day of a project everybody wants the line signed. So nine of the
  eleven lines name an evidence source and ask the service that OWNS the fact - "retention
  collected" is refused while a claim sits unpaid, "site demobilized" while plant is still out,
  "final billing collected" while an invoice has a balance. The person still signs; nobody is
  replaced by a query. A signature plus evidence is stronger than either alone.
  **The other two say `manual` out loud rather than pretending.** `final_project_pl` and
  `scorecards_filed` are P5-09's deliverables and the build holds no fact to check them against yet.
  They get slide 9's baseline - a name, a time, a note - and when P5-09 lands, two config keys change
  from `manual` to a real source and no code moves. Naming the gap is better than a check that only
  looks like one.
  The evidence key is COPIED onto the row at open, not read from config at report time: the
  checklist records what was asked at close-out, and upgrading a line next quarter must not restate
  a report already signed. Same rule as P5-04's pack.
  **The checklist is now a precondition of closing.** It is not paperwork beside the close, it IS
  the close, so `ProjectCloseoutService` gained a fourth reason - no checklist at all, or one not
  cleared line by line. P5-07's "every reason at once" test moved from 3 to 4, which is the correct
  consequence rather than a regression.
  `report()` is the exit gate's second sentence as a query: panel, item, who cleared it, when, and
  the note. P5-11 reads it.
  PHPStan caught `retentionFailure()` declaring `?string` when it is only reached on the failing
  branch and therefore always returns one. Tightened rather than left as a lie about the type.
  Next: P5-09, final project P&L and forecast to completion; scorecards filed.
- 2026-09-13 * **iter 59** * P5-09 done. The final account - slide 9's financial close, plus the
  scorecards from its people-and-assets panel.
  **Life to date, not one month.** P4-08's `profitAndLoss()` answers "what did this project do in
  May", which is what a monthly consolidation asks. The close-out question is what the project made
  over all of it, and a report readable only a month at a time gets summed by hand into a
  spreadsheet nobody can audit.
  **Filed as a snapshot, and this is the ONE stored figure in the phase.** The reason is narrow
  enough to defend: the P&L half is reproducible because the ledger is append-only, but the FORECAST
  half reads open commitments, which change with every order raised or cancelled. A close-out report
  whose numbers differ next quarter is not a record of what the project made. There is a test that
  raises an order after filing and asserts the filed figure does not move while the live forecast
  does.
  `committed_cost` is what makes it a forecast rather than a restatement: an approved order not yet
  delivered sits in no ledger category - the cost arrives with the goods - but ignoring it under-reads
  the outturn by exactly what somebody has already promised to spend. Revenue remaining is floored at
  zero, because over-billing happens and a negative "remaining" reads as money owed back.
  **`vendor_scorecards` learned to rate a SUBCONTRACT, which is the half P1-14 could not build.**
  That task rated a supplier per purchase order, right for goods: delivery, rejection rate, documents
  off the receiving report. A subcontractor delivers works, has no receiving report and often no
  order at all, so there was nowhere to put the card at the one moment anybody assesses them.
  `purchase_order_id` is now nullable, `subcontract_id` joins it, and
  `vendor_scorecards_rates_exactly_one_subject` refuses both and neither - a card rating nothing
  scores a supplier on no work, and one rating both counts a quarter twice.
  A subcontractor's quality is scored from **back-charges as a share of the subcontract** - P5-02's
  ledger-backed figure, read rather than re-derived. Price and documents are not invented from
  nothing; they take the quality score rather than a flattering 100 that would dilute a bad card.
  Filing is refused over an unrated supplier, and it NAMES them.
  **Both of P5-08's `manual` lines are now evidence-backed**, which is that seam working exactly as
  designed: two config keys changed from `manual` to real sources and no code moved except two new
  match arms. One line stays manual and says so - nothing in the build knows whether the as-built
  drawings reached the archive. P5-08's manual-line test moved onto it, which is the correct
  consequence rather than a regression.
  The fixture gained `revenue()->post($invoice)`: revenue reaches the ledger at the invoice (P2-09),
  and without it the final P&L reported a project that cost money and earned none. Caught by the
  test, not by reading.
  Next: P5-10, Filament screens for the Phase 5 documents.
- 2026-09-13 * **iter 60** * P5-10 done. The close-out chain on screen: eight resources -
  punchlists, back-charges, warranties, turnover packs, demobilizations, retention releases, final
  accounts, close-out checklists - and one report page.
  **Read-only throughout, and here the rule has more teeth than usual.** Every document in this
  phase is the output of a service that refused something first: a punchlist item cleared without a
  name, a back-charge on an open defect, a turnover accepted over an unfinished punchlist, a
  checklist line certified over evidence that contradicts it. A form would produce rows that look
  identical to the checked ones and are not.
  **The close-out report is the screen the phase is for.** The exit gate asks that "the close-out
  report names who cleared each item and when", and a list of checklists does not say that - so the
  checklist resource has a view page grouping the eleven lines by slide 9's three panels, each
  naming its clearer, the time and the note. Grouped in the page rather than in Blade: a template
  deciding which panel a line belongs to would be a second copy of the config's own grouping.
  **An uncleared line reads "Not cleared", not an empty cell.** A blank reads as "no data"; this one
  is somebody's outstanding work, and the report is what tells them.
  Three status columns are computed from the underlying date rather than stored, which keeps the
  screens honest with the schema: a punchlist reads `closed_at` (never a count of rows, because an
  empty list nobody walked has no open items either), a turnover pack reads `accepted_at`, a
  retention claim reads `collected_on` - P5-07's whole distinction, visible as "Uncollected".
  The console gate went from 33 to **41**. The demo seeder does not run a close-out - a project
  cannot reach one without a defects liability period elapsing, and the demo data is dated this year
  - so the new gate tests assert the empty state renders cleanly. That is still the check worth
  having: an empty Filament table with a broken column callback throws in the browser and nowhere
  else.
  Next: P5-11, the Phase 5 exit gate.
- 2026-09-13 * **iter 61** * P5-11. **The Phase 5 exit gate passes locally. Phase 5 closes,
  Phase 6 opens.**
  The gate is two sentences and the first is a refusal: "a project reaches close-out and CANNOT BE
  CLOSED until retention is collected." The central test walks one project from substantial
  completion to a closed book through the real services - punchlist, back-charge, turnover,
  acceptance, final billing, collection, demobilization, retention, final account, checklist - and
  asserts the close is refused at the moment that matters.
  **Not the obvious moment.** The refusal proved here is retention CLAIMED BUT UNPAID: the client has
  agreed, the paperwork reads as finished, and the money has not arrived. Refusing an unclaimed
  balance is easy and any design gets it right. The claimed-and-unpaid case is what separates
  "released" from "collected", and it is the reason P5-07 split the claim from the money at all. A
  build that had conflated them would pass a gate worded "until retention is collected" while
  closing projects on a promise.
  The test then walks past a SECOND refusal - retention is in, but nobody has signed the checklist -
  before the project closes. Both on the way through, in one test, because a gate assembled from
  seven independent assertions proves seven things and not that they compose.
  Second sentence, second set of tests: the report names who cleared each item and when, across all
  three of slide 9's panels, and it stays readable after the project is closed. A report that exists
  only while the project is open is not a record, and "who cleared this" is asked months later.
  Full check list across the whole app: **pest 932 passed / 1898 assertions**, pint clean, phpstan 0
  errors at level 5, 110 migrations clean, console gate 41 passed, laravel.log empty.
  **The "on staging" clause is unmet for the fifth phase running.** P0-16 is blocked on a server, not
  on code, and no phase in this build has satisfied a staging clause yet.
  **Phase 6 opened**, its eight tasks expanded from PHASE-PLAN.md Part C. Entry conditions recorded
  honestly: all four chains do post to the ledger, so the first is met; B4 is still not commissioned,
  and in this phase that gap is an EXIT gate clause rather than an entry one - Phase 6 can be built
  but cannot be closed on it. The task table separates what this build can prove from what needs the
  server, so the phase cannot be quietly declared done on the half that is code.
  Next: P6-01, per-project row-level policies.
- 2026-09-13 * **iter 62** * P6-01 done. Per-project row-level access - PLAN.md section 3, deferred
  since P0-14 with the reason written on the User model, and now built.
  **It is a global query scope, not a view filter, and that is the whole design.** A screen that
  filters what it renders leaves every hidden record addressable: the row is still in the result
  set, still counted in a nav badge, still returned through a relation, still in an export. Applied
  on the model, the restriction survives every route to the data - including routes written after
  this class. There is a test that looks a record up BY ID from another project and gets null.
  Three decisions about how it fails, each chosen against the direction that fails open.
  **No assignment means no projects, never all of them.** The classic form of this bug reads an
  empty assignment list as "no filter to apply" and hands a new starter the whole company on their
  first login. Tested directly.
  **Unauthenticated means unscoped.** A queued payroll run, a console command, the scheduler and a
  migration all execute with no user. Scoping them to nobody's projects would not restrict anything
  - it would silently stop the payroll.
  **Roles that see everything are named in ONE place.** Finance closes the books across projects and
  the managing director signs at tier 4. Exempting them per screen would put a bypass in every
  resource, and the one somebody forgets is the one that matters. PLACEHOLDER: which roles carry
  company-wide visibility is not in Part D; the three named follow from the authority matrix the
  build already seeds.
  The escape hatch is `withoutProjectScope()`, deliberately not a query macro. `withoutGlobalScope()`
  reads as a convenience; stepping around a row-level access rule should read as a decision, and
  every call site can be found by searching for the name.
  **19 screens tests broke, and they were right to.** They acted as a bare factory user with no
  assignment and then asserted a record was visible - which under a real policy is exactly what
  should not happen. Fixed by saying who is looking: `panelUser()`, an administrator, which is who
  actually opens those screens. The seeded local admin gained the same role, or `migrate:fresh
  --seed` would have left a login that can see none of what it just seeded.
  Next: P6-02, the activity-log export.
- 2026-09-13 * **iter 63** * P6-02 done. The activity-log export - the practical half of PLAN.md
  section 3's append-only trail. That section made the log immutable because "this system produces
  an audited P&L, so who changed what and when is not optional"; a trail nobody can get OUT of the
  database satisfies that only in principle, and B4's whole point is that somebody outside the
  company reads it.
  **A file, not a screen.** An auditor works from a spreadsheet they can filter, keep and attach to
  a working paper. Paginating three years of entries through a browser is a way of not producing
  them. It also runs from the console - `php artisan activity:export --from= --to=` - because the
  request arrives as "the auditor wants June to August" and is answered by somebody on the server,
  not by somebody with a browser on the box.
  **The private disk**, like the payslips and the disbursement file. Every row names a person and
  what they did; the public disk would make the company's audit trail a URL.
  **Deliberately UNSCOPED, which is the opposite of what P6-01 just built.** That task restricts
  what a user may read of live project data. This one must not: an auditor reading one project's
  entries is not the case it exists for, and a partial audit trail that looks complete is worse than
  none. It goes through `ProjectScope::withoutScope()`, so the decision is findable by name rather
  than implicit.
  Four smaller judgements. The **header is asserted literally in a test**, because a CSV whose
  columns move between exports cannot be compared to the one filed last quarter - which is most of
  what an auditor does with it. The range is **inclusive at both ends** and **required**: somebody
  will ask for a calendar month, and defaulting to "everything" would make the biggest possible file
  the easiest possible request. An **empty period still writes a header**, because "nothing happened
  then" is an answer that has to be given in writing. And the causer is exported by **name and
  email**, not id - the auditor is reading this without the database beside them.
  Rows are chunked at 500. The whole point of the class is a period long enough that loading it into
  memory is what fails on the day it is needed.
  One test-quality note: the boundary test first asserted a row COUNT and was measuring the fixture -
  creating the users who make a change is itself logged. Rewritten to assert membership by marker,
  which is what the boundary actually means.
  Next: P6-03, indexes against five years of simulated document volume.
- 2026-09-14 * **iter 64** * P6-03 done, and a security finding from the previous commit fixed
  first.
  **The security fix.** A background review flagged CSV formula injection in P6-02's exporter, and
  it was right: that file exists to be opened in Excel by an auditor, and a cell beginning =, +, -
  or @ is executed on open. Descriptions in this system are written by its users, so a value typed
  into a punchlist would have become code running on the machine of the person auditing the company.
  Values are now prefixed with a tab rather than stripped - the auditor must still see what was
  actually recorded, and deleting characters from an audit trail to make it safe to read is worse
  than the injection. Three tests, written before the fix, and the assertion is **per CELL** because
  that is the unit a spreadsheet evaluates: the payload also appears inside the JSON changes blob,
  whose cell begins with a brace and is therefore text, and prefixing that would corrupt JSON an
  auditor may want to parse. A plain negative number is left alone for the same reason - it leads
  with a minus and is not a formula.
  **P6-03 asserts query PLANS, not wall-clock times**, and that is the whole design of the suite. A
  timing assertion on a developer laptop measures the laptop: it passes on a fast machine with a
  missing index and fails on a slow one with every index in place. What actually degrades over five
  years is a query that reads the whole table, and MySQL says so in EXPLAIN on twenty rows exactly
  as it does on two million. The volume is there so the optimiser stops preferring a scan out of
  laziness, and `ANALYZE TABLE` runs after loading it so the plans are planned against it.
  **A composite index is asserted by its COLUMNS AND THEIR ORDER, never by name.** An index on
  (category, project_id) will not serve a query filtering project_id alone, and a test checking only
  the name would pass on it.
  **One real gap found, which is what the task is for.** `project_cost_ledger_pnl_index` was
  (project_id, category), and every P&L, every consolidation row and the whole final account filter
  on those two AND range on `document_date`. MySQL uses a composite index only up to the first
  column not in it, so the date range was resolved by reading every row the first two matched - on
  five years of one project's material postings, the whole category. `document_date` goes LAST,
  after both equality columns: an index is usable up to and including the first range predicate, so
  a range column placed before an equality one throws the equality away.
  The migration adds the new index BEFORE dropping the old one. `project_id` is a foreign key and
  InnoDB refuses to drop the only index backing one - and the error names the constraint rather than
  the index, which is how that reads as a mystery for ten minutes.
  The volume seeder writes through the query builder rather than `LedgerPoster`, a deliberate
  exception to this build's fixture rule: the service is not what is under test and 2,400 postings
  through it would make this suite slower than the whole rest of the run. A test asserts the two
  append-only triggers survive the shortcut, so the ledger's core promise is not quietly disabled by
  the fast path.
  PLACEHOLDER: the volume is a plausible mid-sized contractor, not the client's. Part D item 13's
  headcount would let it be sized from real numbers.
  Next: P6-04, backup and restore verified against a scratch database.
- 2026-09-14 * **iter 65** * P6-04 done. Backup, restore, and the rehearsal that proves the restore
  works - PHASE-PLAN.md puts it in bold in the build list and then repeats it as **exit gate clause
  2**, which is the plan saying the same thing twice on purpose.
  **A backup nobody has restored is not a backup.** It is a file whose contents nobody has checked,
  and the first time anybody checks is the morning they need it. So the deliverable is not a dump
  command: `php artisan backup:rehearse` takes a real dump, loads it into a scratch database, and
  reports what came back.
  **It counts the ledger's two TRIGGERS, and that is the check worth having.** The ledger is
  append-only because of them, `mysqldump` omits triggers unless asked, and those flags are the
  first thing lost when somebody rewrites a backup script. A restore that brought every row back and
  left them behind returns a table anybody can edit - and nothing about the restored database would
  look wrong. The rehearsal fails loudly on a trigger count of anything but 2.
  **Three refusals before anything is written**, because this is the one operation in the build
  capable of destroying a database. Never the connected database - checked FIRST, because it is the
  most dangerous target and deserves the most specific message, and because a name that happened to
  carry the marker would otherwise sail past it. Never a name without `scratch` in it - a marker
  rather than a blocklist, since a blocklist is only as good as the last person who remembered to
  add to it and the databases worth protecting are the ones nobody thought of. And never an empty
  dump: zero bytes is the classic silent failure - the cron ran, the file exists, the disk was full -
  and restoring it drops the target and puts nothing back.
  One real test-design problem, worth recording because it would have made the suite a lie. The
  first run dumped a database that did not contain the seeded row: `mysqldump` is a separate process
  and the suite runs inside `RefreshDatabase`'s transaction, so nothing the test created was
  visible to it. The dump came back without the data while every in-process assertion still passed.
  Fixed by writing the marker rows on a **second, committed connection** and removing them in
  teardown - anything an external process must see has to be committed, which is precisely what a
  backup test is about.
  PHPStan caught `$this->scratch` as an undeclared dynamic property on a Pest test. Replaced with a
  named fixture function, which is where a constant the service refuses to run without belongs
  anyway.
  `RUNBOOK.md` written: what the rehearsal checks and why each one, the guards, taking a backup on
  the server, and **restoring for real** - which is deliberately not automated. Stop the workers,
  restore to scratch FIRST and look at it, promote only then, migrate, and restart the workers after
  the migration rather than before, or the first payroll run after a restore executes the previous
  release and the symptom is wrong numbers rather than an error.
  Clause 2 is **green locally and in CI, and not on a server**, because there is no server.
  Next: P6-05, 2FA on every Finance, HR and Admin account - exit gate clause 4.
- 2026-09-14 * **iter 66** * P6-05, and the second security finding of the phase fixed first.
  **The security fix.** The review flagged credential exposure in P6-04's `BackupService`: the
  database password was passed as `--password=` on the command line, where `ps` shows it to every
  user on the box for as long as the process runs. Both processes now get a temporary
  defaults-extra-file instead, created 0600 **before** anything is written into it - created first
  and chmod'd second would leave a window where the password is world-readable, which is the same
  bug in miniature - and deleted in a `finally` so a throwing dump does not leave it on disk.
  **P6-05: 2FA on Finance, HR and Admin.** The secret is `encrypted` at rest under the rule PLAN.md
  section 3 already applies to bank details and government numbers: a TOTP secret in plaintext is a
  second factor anybody with a database dump holds too. A test reads the raw column to prove it.
  **Enabling is not confirming**, and they are separate columns for a reason: a secret generated but
  never verified means somebody scanned a QR and closed the tab, and enforcing on the secret alone
  would lock them out with a factor they never proved they can produce. **A used code cannot be
  replayed** - a TOTP window is thirty seconds wide, so a code read over a shoulder is good for the
  rest of it - and the service is a SINGLETON so that memory is one memory rather than one per
  resolution. **Disabling is refused for a covered role**, because that is how clause 4 stops being
  true quietly three months after go-live: not by a decision, but by one person finding it
  inconvenient.
  Enforcement is middleware on the panel's auth stack, not a check per screen - the same reasoning
  as P6-01's query scope: a rule applied per resource is one somebody forgets on the resource that
  matters, and here that is whichever screen gets built next. Roles are configured and deliberately
  **not widened** beyond the three the gate names; enforcing on a site role would lock a timekeeper
  out over a rule nobody agreed to.
  **THE GAP, stated rather than glossed: this enforces ENROLMENT, not a per-login challenge.** A
  covered account cannot reach the panel until it has a working authenticator, but it is not asked
  for a code at each sign-in. That is the other half of what "2FA is enforced" means, and clause 4
  is therefore **partially** met. Recorded as **P6-05a** so the exit gate cannot be claimed on the
  half that is built.
  Two knock-ons handled. The screens fixtures' `panelUser()` is an admin, so it now enrols - a
  fixture that skipped it would assert on a panel the gate does not let that account into. And the
  seeded local admin enrols too, rather than being exempted: an exemption would be a hole in clause
  4 that starts life in the seeder and is still there when somebody runs it against staging.
  One test-quality note: `getCurrentOtp()` takes no window argument, so the "accepts a later code"
  test was silently asking for the same code twice and failing on the replay guard it was meant to
  prove does not over-reach. Fixed to `oathTotp()` with an explicit window.
  Next: P6-06, Horizon, failed-job alerting and log rotation.
- 2026-09-14 * **iter 67** * P6-05a done. The per-login challenge - the half of exit gate clause 4
  that the previous iteration named as missing rather than glossed, and **clause 4 is now fully
  met** locally.
  P6-05 stopped a covered account at the panel door until it held a working authenticator. That does
  nothing about a stolen password, which is the thing a second factor is for. So the code is asked
  for at sign-in.
  **Once per session, not once per request.** A challenge on every page load is one people route
  around, and what is being authenticated is the session rather than the click. **The pass is
  session state and never a column** - a column would make the factor satisfied on every device the
  moment it was satisfied on one, which is precisely the property a second factor exists to deny.
  There is a test that flushes the session and asserts the challenge returns.
  The session id is **regenerated before** the pass is recorded: a session id that survives the
  second factor is one an attacker who fixed it beforehand now holds authenticated.
  Order matters in the middleware and is tested: unenrolled goes to SETUP, enrolled-but-unchallenged
  goes to the CHALLENGE. Challenging somebody with no authenticator asks them for a code nothing can
  produce - a locked door with no key rather than a second factor.
  **A real usability bug fell out of the replay guard, and the fix is the right one.** Enrolling and
  then being immediately challenged asks for a code the authenticator will keep showing for up to
  another thirty seconds - which the replay guard then correctly refuses, leaving an account locked
  out for half a minute after doing exactly what it was told. Confirming enrolment now satisfies the
  session, because confirming IS a proof of possession at that moment.
  **No test bypass anywhere, and this was the decision worth making.** No test-only route, no
  "disable 2FA in testing" flag, no exemption on the seeded admin. Any of those is a hole in clause 4
  that lives in the codebase and reaches staging the first time somebody copies the config. Instead
  the browser gate does what a phone does: `tests/Browser/totp.js` implements RFC 6238 in about
  forty lines of Node, reads the secret the seeder writes in local and testing only, and answers the
  challenge. PHP and JS were cross-checked to produce the same code before it was wired in. The
  secret file is gitignored.
  Two knock-ons, both correct consequences rather than regressions. `panelUser()` now also carries a
  session pass, since the fixture stands for an administrator who has signed in and answered. And
  P6-05's "lets the same account in once it is enrolled" became "stops sending it to setup" - it now
  redirects to the challenge, which is the accurate statement of what enrolment alone earns.
  One browser-test note worth keeping: the first attempt checked the URL immediately after clicking
  Sign in and saw `/admin`, because the redirect to the challenge had not happened yet - so it
  skipped the second factor and failed on the title. Waiting for the load state to settle before
  branching is the fix.
  Next: P6-06, Horizon, failed-job alerting and log rotation.
- 2026-09-14 * **iter 68** * P6-06 done, with Horizon split out as P6-06b and marked **blocked**.
  **A failed job nobody hears about is a payroll nobody ran.** Laravel records a failure in
  `failed_jobs` and tells nobody; a failed `ComputePayrollRun` does not appear as an error on
  anybody's screen, it appears as a register that never arrived, noticed on payday. So a queue
  failure raises an alert in `job_failure_alerts`, addressed to the role that can act - payroll to
  `finance-manager`, anything unmapped to `admin` rather than to nobody - and held open until a NAMED
  person acknowledges it and says what was done. F13's escalation pattern, for the reason F13 needed
  it. Repeats of the same job fold into the open alert with a count, so a worker retrying every
  minute raises one alert rather than sixty; a fresh failure after acknowledgement is news and raises
  a new one. `ops:job-failures` exits non-zero while anything is open, for an uptime monitor.
  **Alerting must not be able to break what it reports on.** The listener runs in the worker's own
  failure path, so `recordSafely` swallows its own exceptions as a warning. The first version of that
  test fed it bad config, which `ownerOf` tolerates - the catch was never reached and deleting it
  would not have failed the test. Rewritten so the alert write genuinely throws.
  Registered as a closure rather than a class in app/Listeners: event discovery would register a
  listener class a second time and every failure would count twice.
  **Log rotation, and one thing the change quietly broke.** `single` grows one file until the disk is
  full, and a full disk stops MySQL before anything else. Switched to `daily`, 30 days - which needed
  `.env` and `.env.example` changed too, since both pinned `LOG_STACK=single` and would have overridden
  the new default silently. The catch: daily logs are `laravel-YYYY-MM-DD.log`, so the loop's own
  Step 5 check on `laravel.log` would have gone on reporting "clean" off a file nothing writes to.
  **BUILD-LOOP.md corrected in the same commit**, and the claim was then proven rather than asserted:
  a probe line landed in the dated file while `laravel.log` stayed at zero bytes.
  **The schedule**: prune failed jobs daily, check for open alerts hourly, rehearse the restore on the
  1st of each month - P6-04's "a restore verified in March is not a restore verified", written into
  the scheduler instead of somebody's memory. Deliberately absent, and asserted absent: anything that
  prunes `activity_log`, which is append-only and is what B4 reads.
  **P6-06b, Horizon, is blocked - and verified blocked rather than assumed.** A dry run of `composer
  require laravel/horizon` refuses on missing `ext-pcntl` and `ext-posix`, and there is no Redis on
  this machine; the dry run was confirmed to leave composer.json and composer.lock untouched. The
  install steps are in RUNBOOK.md section 7. Nothing built here changes under Horizon: the alerting
  listens for `JobFailed`, which Horizon workers raise too.
  PHPStan caught a Mockery mock passed where `JobFailed` requires a real `Job`. The fix was not a
  type annotation: Laravel ships a concrete `SyncJob` that resolves its name from a real payload,
  which is closer to what a worker raises than the mock ever was.
  Next: P6-07, the server hardening runbook.
- 2026-09-14 * **iter 69** * P6-07, recorded as **done for the configuration and blocked for applying
  it**, because there is no server and nothing in `ops/` has run on one.
  Five files - SSH, fail2ban, PHP-FPM pool, PHP INI, MySQL - each setting whose absence fails
  SILENTLY pinned by `ServerHardeningTest`, the way P0-16 pinned `deploy.sh`. A hardening file that
  drifts is one nobody notices has drifted, because the server still boots. The parser skips
  commented-out lines, and that guard has its own test: `# PasswordAuthentication no` in a file is a
  note, and a test matching raw text would pass on it.
  **One real defect, found and then REPRODUCED rather than reasoned about.** MySQL 8 turns binary
  logging on by default, and with it on, `CREATE TRIGGER` requires `SUPER`. The ledger is append-only
  because of two triggers created by a migration, and `.env.staging.example` specifies a
  least-privilege database user. On the local 8.4.3 server a throwaway user holding the `TRIGGER`
  grant got `ERROR 1419 ... You do not have the SUPER privilege and binary logging is enabled`. So
  the first `migrate --force` on a real server would have failed on exactly the migration that makes
  the ledger immutable. **All 1045 tests never caught it because the suite connects as root**,
  which holds SUPER. Fixed with `log_bin_trust_function_creators = 1`; granting the application user
  SUPER instead would be far less safe. The probe user and database were dropped afterwards.
  Two more that would have failed quietly, both caught before writing rather than after. The X
  Protocol: `bind-address` covers port 3306 only, and `mysqlx_bind_address` defaults to `*` on 8.4 -
  verified on the local server - so 33060 would have listened on every interface. And SSH: sshd uses
  the FIRST value it reads, drop-ins load alphabetically, so a file named `99-` loses to a provider's
  `50-cloud-init.conf` setting `PasswordAuthentication yes`. Renamed `00-construction.conf`, and the
  test pins the name because every other SSH assertion would still pass on the wrong one.
  Self-correction worth recording: the first draft of the test asserted `expose_php` in the FPM pool.
  It is a system-level directive and a pool's `php_admin_*` is not a reliable place for it, so that
  test could have passed while doing nothing. Moved to a conf.d INI where it certainly applies.
  Deliberately NOT done: a fail2ban jail on the application login. Filament's sign-in is Livewire, so
  a failed attempt is a POST to `/livewire/update` - the same URL as every click on every screen. A
  log-based jail cannot tell a wrong password from somebody paging a table.
  RUNBOOK.md section 8 has where each file goes and the check to run before each reload. DEPLOY.md
  section 3 brought up to date: it still listed the restore rehearsal and 2FA as future work.
  Verification note: the full suite and the console gate ran concurrently this time, so neither
  truncated the shared log - that could erase an ERROR the other had just written. Each counted only
  the bytes appended after its own start instead.
  Next: P6-08, the Phase 6 exit gate.
- 2026-09-14 * **iter 70** * P6-08, the Phase 6 exit gate - and two gaps it found before it could pass.
  **The gate walks §7 on the SEEDED demo, not on fixtures.** §7 is a demo; a gate that built its own tidy
  project would prove the services work while the thing a reviewer actually opens went untested. On
  `MBI-2026-014` it proves all four chains reach the ledger, an over-budget PR is refused, the OPEX close
  is refused over its variance, and the P&L and cost per unit assemble.
  **Two things §7 requires had never been built, and walking it step by step is what found them.**
  **P6-08a, cost per unit of accomplishment** (§7 step 9). The phrase sat in docblocks from P1-15 onward -
  `ConsolidationService` even quotes it - and nothing computed it. Now: life-to-date cost divided by the
  VERIFIED percentage, because an unagreed percentage would let the figure improve by measuring
  optimistically. Nothing verified reads *not measurable*, never zero, since zero cost per point reads as
  free work. Benchmarked against the OPEN budget, so it says whether it is good. Read through
  `FinalAccountService`, so it and the final account cannot disagree about what a project cost.
  **P6-08b, the demo seed guard.** §7: the reset "can never point at production". Two seeder docblocks
  promised this to Phase 6 and it existed only in those comments. RED proved it rather than inferred it:
  with the app believing it was production, `db:seed --force` exited **0** and seeded the entire demo.
  Laravel's own production confirmation on `migrate:fresh` is skipped by `--force`, so the guard lives in
  the seeders, first line, before any row. Verified on the REAL command line too, not only under Pest:
  exit 1, and it refused before it even connected - pointed at a database that does not exist, it never
  got as far as failing on that.
  **The worse finding beside it: every seeded account had the password `password`, in every
  environment** - the admin AND an account for every role the authority matrix routes to. On production,
  a backdoor into the role that reads across every project; on staging, the same on a public subdomain.
  Outside local and testing the seeders now require DEMO_ADMIN_PASSWORD and refuse `password`. An unknown
  environment name is treated like staging, not like local.
  Four things caught in my own drafts before they could mislead. The budget check lives in `submit()`,
  not `raise()`, so the first gate draft would have failed for the wrong reason. The cost-per-unit page
  test created no project and the label renders per project, so it would have kept failing after the fix.
  The exit-code tests expected exit 1, but Laravel's console layer does not catch exceptions under
  `artisan()` in tests - read in the framework source, then the tests expect the refusal and still assert
  nothing was written. And both new seeding suites fake the local disk: `DatabaseSeeder` writes the admin's
  TOTP secret in testing, and the real file is the browser console gate's key to the DEV database, so
  running the suite would have broken `npm run test:console` with nothing in its output pointing here.
  **Phase 6 is NOT closed, and that is the accurate state rather than a caveat.** Green locally: clause 1's
  walk-through, clause 2 and clause 4. Unmet and not claimable by code: clause 1's "on staging" (P0-16,
  Part D item 12 - no phase in this build has satisfied a staging clause) and clause 3, the B4 audit, now
  recorded in DECISIONS-PENDING.md. Go-live readiness waits on a server and an auditor, not on code.
- 2026-09-14 * **iter 71** * **2FA switched off for now, at the client's request**, to be enabled later.
  A switch, not a removal. `TWO_FACTOR_ENABLED` (config `security.two_factor_enabled`, default false) now
  gates the enforcement middleware: while it is off, Finance, HR and Admin accounts go straight into the
  panel with no authenticator setup and no code. Enrolment, the per-login challenge, the replay guard and
  the encrypted secret all stay built, and their suites switch the flag ON for themselves so they keep
  proving it works for the day it is re-enabled. Enabling it again is `TWO_FACTOR_ENABLED=true` in `.env`.
  **While it is off, Phase 6 exit gate clause 4 does not hold**, and that is recorded rather than glossed:
  `security:two-factor-status` now reports "switched off" and exits 1 instead of showing an empty
  outstanding list that would read as green.
  pest 1075 passed / 2197 assertions · pint passed · phpstan 0 errors · console gate 41 passed
  (signed in as a covered account straight to the dashboard, no challenge) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 72** * **Per-screen access by role (P6-01a)** - a gap found while answering "how many
  roles do we have", and the reason `hr-manager` meant almost nothing.
  **Until this, every signed-in user could open every screen.** P6-01 limited which PROJECTS a user's
  queries return; nothing limited which SCREENS they could open. A site foreman could read every payroll run
  and payslip, and anybody could open the approval-matrix admin and redefine approval authority. P0-14's
  notes said per-screen authorisation would arrive with Phase 6; there was no policy, no gate and no
  `canViewAny` anywhere in `app/`.
  **Enforced on the page, not hidden from the menu, and that distinction was proven rather than assumed.**
  Filament's default for a custom page is to allow any signed-in user, and with no policies its create,
  edit and view checks fall through to allow. RED showed it: a user with no role got 200 on the vendor
  create and edit pages and the approval-matrix create page. So there are two layers - `canViewAny` on all
  36 resources, which drives the navigation, and `canAccess` on all 41 resource page classes plus the P&L
  page, which Filament runs on mount AND on every Livewire request. The page layer calls Filament's own check
  after its own, so it can only narrow access, never widen it.
  **Fail closed.** A screen with no rule is admin-only, and a test iterates what Filament actually registers
  and asserts every screen has a rule - so a screen added later can neither slip in open nor lock everybody
  out unnoticed. Resolution is super role, then a rule for the specific screen, then its navigation group:
  that is how payroll deductions, filed under OPEX in the menu, is still HR's and not project managers'.
  **The map is configuration** in `config/access.php`, because Part D item 5 has not named who owns each
  step - a test proves changing a group's roles changes access without code. Payroll goes to HR and finance;
  a project manager is refused salaries but keeps procurement, billing and close-out.
  The two rules compose: the close-out report test first gave a project manager 404, not 200, because they
  were not assigned to the project and P6-01's scope hid it. The screen rule opens the page; the project
  scope still decides whose data is on it. Four existing suites that signed in as a role-less user started
  getting 403 and were right to - updated to say who is looking, with the least role that does the job
  (the vendor suspension actor is `procurement-head`, not admin).
  pest 1159 passed / 2312 assertions · pint passed · phpstan 0 errors · console gate 41 passed ·
  daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 73** * **One demo account per role** (`DemoAccountsSeeder`), at the client's request for
  sample accounts.
  Per-screen access (P6-01a) made each role see a different system, but the demo seed had only created the
  three accounts its sample documents happened to need - admin, project manager, procurement head. Nobody
  could sign in as HR, finance or the managing director. Now six accounts, one per role, emails in the
  existing `role.name@construction.test` pattern, passwords from DemoSeedGuard (the default locally; the
  existing staging test already refuses the default on every seeded user, the new three included).
  **A real gap found while listing them: the seeded project manager saw an empty system.** P6-01 hides
  every project a user is not assigned to, and nothing assigned them to the demo project - so they signed
  in to no projects and no punchlists. They are now assigned to `MBI-2026-014` as project manager. They are
  the only demo account that needs it: finance, the managing director and admin read across projects by
  role, and the procurement and HR screens are not project-scoped.
  Safe to run on an existing database: accounts are found before they are created, passwords are never
  overwritten, and an existing assignment is left alone - tested by running it twice. `admin` stays owned
  by DatabaseSeeder with its 2FA enrolment; two seeders writing one account is how they drift.
  One test-design note worth keeping: the first draft signed in as four users inside one test and got a
  500. The panel's AuthenticateSession middleware stores the signed-in user's password hash in the
  session, so switching users mid-test logged the next one out - and the logout redirect then crashed on
  the Livewire redirector still bound from the previous render. No real user can hit it, since each signs
  in through their own session; confirmed in the framework source, then fixed by giving each account its
  own test. Its finance data set also named a screen finance is in fact allowed; corrected to the approval
  matrix, the one screen finance is refused.
  **The finance and HR demo accounts are deliberately NOT enrolled in 2FA**, and the full suite caught what that
  changes. The Phase 6 exit gate's clause 4 test assumed the admin was the only covered account on a fresh seed;
  with finance and HR added, the status command correctly reported them outstanding and the test failed. Enrolling
  them in the seed would be wrong: the admin can be enrolled only because its secret is written for the browser
  gate, and a secret nobody was shown would lock these accounts out the day TWO_FACTOR_ENABLED is turned on.
  Unenrolled, they reach the setup page on first sign-in - what a real new finance or HR user should see. The test
  now enrols every covered account before asserting green, which is the property it exists to prove.
  pest 1179 passed / 2364 assertions · pint passed · phpstan 0 errors · console gate 41 passed ·
  daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 74** * **Employees can be added and edited on screen**, at the client's request - "how will I
  add employees, edit their name, SSS, Pag-IBIG".
  The employee register had been read-only, like every document screen in the build. That rule is right for
  payroll runs, DTRs and disbursements - each is the output of a checked process, and a typed figure would
  look exactly like a computed one. It was wrong for employees: a person is master data, and HR had no way to
  add one or correct a name except the database. Only Vendors and the Approval Matrix had forms.
  **Every save goes through EmployeeService, not around it.** Filament's default create writes the row
  directly, which is what the Vendors pages do; the employee pages override the create and update hooks.
  `hire()` now refuses a missing required field and a duplicate employee number with a readable message
  (previously a duplicate reached the screen as a database error); the unique index still stands behind it,
  and a test proves a direct write is still refused. A new `updateDetails()` takes an ALLOW-LIST, not
  "everything fillable": the employee number (timekeeping imports match on it), date hired (the contract gate
  reads it), company, status and separation are refused, and the form locks them.
  **Government numbers and the bank account are write-only.** Blank keeps what is stored, a typed value
  replaces it, and the helper text says only whether one is on file. They are checked by digit count - SSS 10,
  PhilHealth 12, Pag-IBIG 12, TIN 9 or 12 - because catching a typo before a remittance file is most of a form's
  value; a checksum rule the build cannot rely on would refuse real numbers. Every refusal lands beside its
  own field. An audit-log entry records WHICH fields changed, never their values.
  **Rate and separation are their own acts.** "Set new rate" appends a dated entry, so April is still paid at
  April's rate after a May raise; "Separate employee" requires how and why. No delete - payroll lines point at
  these rows.
  **The leak test caught something, and it was not a leak.** The edit page contained a TIN - which turned out
  to be the example placeholder, `123-456-789-000`, identical to the test employee's TIN. A throwaway probe
  confirmed the real SSS and bank values appeared zero times. The fix was placeholders that cannot look like a
  number (`NNN-NNN-NNN-NNN`), not a looser test. Filament's own source also warns that an edit form is filled
  from every non-hidden attribute and sent to the browser, so the edit page now strips the identifiers before
  filling - secrecy no longer depends on the order hooks run in.
  **A self-inflicted break, recorded because it hid everything.** Appending the add-employee test to the
  browser spec wrote an escaped newline as a real line break inside a JavaScript string, and the whole file
  stopped parsing - Playwright found no tests at all, so the console gate was silently empty rather than red on
  one screen. Caught because the new test's result was missing, confirmed with `node --check`, repaired.
  `EmployeeTest`'s duplicate-number test changed deliberately from expecting a database exception to expecting
  the readable refusal; `PayrollScreensTest` no longer lists Employees among screens that cannot create.
  pest 1223 passed / 2516 assertions · pint passed · phpstan 0 errors · console gate 42 passed
  (add-employee page added) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 75** * **Site staff can open the screens their jobs are** - the first of the gaps the phase
  plan never covered, at the client's request to finish the local app before staging.
  Per-screen access (iteration 72) gave the office roles their screens and left the four site roles with the
  dashboard and the approvals inbox. A timekeeper could not open the DTR screen they exist to keep; a storekeeper
  could not see a receiving report.
  **Each site role now reaches its job and nothing else.** Timekeeper: DTRs and overtime authorities. Foreman: those
  plus punchlists. Storekeeper: receiving reports, stock cards, equipment and purchase orders. Site engineer:
  requisitions, DTRs, overtime, punchlists, mobilization, permits and receiving. None of them reaches a payroll run,
  the employee register, billing or the ledger, and the tests assert the refusals as well as the access.
  **They are per-screen entries in config/access.php, not new group memberships.** A screen rule replaces its
  group's rule, so each entry repeats the group's roles and adds the site roles; a test proves HR still opens DTRs
  and a project manager still does not. Site roles are not in ProjectScope's unscoped list, so they still see only
  the projects they are assigned to. PLACEHOLDER: Part D item 5 - these follow the job titles.
  **Demo accounts for all four** (timekeeper@, foreman@, storekeeper@, site.engineer@construction.test), each
  assigned to the demo project MBI-2026-014 with the matching project role, so signing in as one shows that project
  and only that one. The seeder's project-manager assignment was generalised to a role-to-project-role map.
  One false alarm, recorded so it is not chased again: the backup tests failed in a narrowed run because the shell
  lacked MySQL's bin directory on PATH (`mysqldump` not recognised). Not a regression; the full run sets PATH.
  pest 1237 passed / 2561 assertions · pint passed · phpstan 0 errors · console gate 42 passed ·
  daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 76** * **Setup screens: projects, cost codes, budgets, equipment and accounts** - the
  second gap the phase plan never covered. Every one of these was seeder-only: the client could not open a project,
  budget it, register a machine or add the people who will sign in, without the database.
  **Each saves through a domain service, never around it** - four new ones (ProjectService, CostCodeService,
  BudgetService, EquipmentRegisterService, UserAccountService) because none existed; the Filament pages override the
  create and update hooks, and every field refusal lands beside its field.
  **Projects.** Open at acquisition, active. Phase moves forward one step at a time, never back, and never to
  post-construction by hand - the completion certificate owns that - and closed is close-out's. Hold needs a reason.
  A closed project refuses every change. The code is fixed. **Only a role that sees every project may open one**:
  P6-01 hides unassigned projects, so a project manager who opened a job would lose sight of it on save; project
  managers edit the jobs they are on. The duplicate-code check reads past the project scope, so a user who cannot
  see the other project still gets a message instead of the unique index.
  **Cost codes.** Code fixed once added, parent in the same company, and the model's existing loop guard now lands
  beside the parent field. Company-wide, so finance's.
  **Budgets.** Drafted line by line, opened and closed as acts. Only a draft changes; opening needs at least one line
  (an empty open budget would pass the requisition gate while budgeting nothing); **one open budget per project**,
  because BudgetActualService and CostPerUnitService add a project's budgets up and a second would count every line
  twice. Budgets are not project-scoped models, so the screen limits rows to budgets whose project the user can see -
  a test proves a project manager gets a 404 on another job's budget.
  **Equipment.** The register gained register and edit; status is still never a field (deploy and release stay the
  row actions). The acquisition figures a depreciation schedule was computed from lock once it exists.
  **Found by the screen test, not the service test:** a rented machine registered with blank cost, life and date
  reached MySQL as nulls on NOT NULL columns. The service test had always supplied every field. Blank now means "not
  given" and the table defaults apply (60 months, straight line); a service test pins it.
  **Accounts - admin only.** Create with roles, edit name, email and roles, "Set password" as its own act, and project
  assignments through ProjectAccessService. Roles must already exist; **the last administrator cannot be demoted**;
  the password hash and two-factor columns are stripped before the edit form fills. PLACEHOLDER: 12-character
  minimum, recorded in DECISIONS-PENDING.md as unnumbered - it had first been tagged "Part D item 11", which is the
  jurisdiction question, and was corrected before commit.
  Process note, stated plainly: the five service tests were written alongside their services rather than watched
  failing first; the screen tests were run red (classes missing) before any screen existed.
  pest 1346 passed / 2889 assertions · pint passed · phpstan 0 errors · console gate 47 passed
  (five setup forms added) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 77** * **Benefits: recurring allowances, service incentive leave and 13th month pay** - the
  last of the gaps the phase plan never covered, at the client's request ("benefits"). None of it is a PHASE-PLAN.md
  Part D item, so the client has never been asked any of it: **every rule is a placeholder**, in `config/payroll.php`
  under `benefits` and one new unnumbered row in DECISIONS-PENDING.md, weighted like item 9 because wrong here is pay.
  **Allowances** are dated like rates - granted from a date, ended on a date, never edited, so a paid cutoff is never
  restated. An amount per cutoff, paid in full for any cutoff in force. Taxable ones go into gross, so contributions
  and withholding are computed on them; non-taxable ones are added to net only. The amount is encrypted and, like
  the rate, never displayed - a test asserts it is absent from the employee page.
  **Service incentive leave**: five days a leave year after twelve months, the year running from the hiring
  anniversary; half days allowed; no carry-over and no cash conversion (policy the build will not invent). Refused on
  a day with a worked DTR, outside employment, twice on one date, or beyond the balance. A run prices unpaid leave at
  the daily rate in force that day and stamps each record with the line that paid it, in the same transaction - leave
  is paid once, and a paid record cannot be cancelled. The foreign key frees the record if its line is ever deleted,
  as a DTR day is freed, rather than blocking the delete.
  **Leave with no worked day in the cutoff is held, and the register says so.** Labour cost posts against the
  projects days were worked on, and a line with no days has nowhere to post.
  **The ledger still matches the register.** LaborCostPoster summed only day amounts, so leave pay and allowances
  would have gone missing from project cost. Each line's benefits are now spread across the projects it worked on in
  proportion to pay earned, remainder on the largest share; a test proves the posted total equals gross plus
  non-taxable allowances to the centavo.
  **13th month** is computed from approved and released registers - never a computed one, never a rate multiplied
  out: one twelfth of basic pay plus paid leave, split at a configurable tax-exempt ceiling. A report page under
  Payroll, for HR and finance. **Not paid out**: whether it is its own December run, the last cutoff or two halves is
  the client's call, and the build does not pay anybody on a guess.
  Payslips show paid leave, taxable and non-taxable allowances when non-zero; a test renders the PDF with all three.
  Existing registers are untouched: every earlier payroll, posting and exit-gate test passes unchanged, and a test
  proves a run with no benefits writes exactly what it wrote before.
  Process note, stated plainly: AllowanceService and LeaveService were written before their tests were run, so those
  two files never went red; the payroll integration, posting and 13th-month company list did go red for the right
  reasons (empty columns, unspread posting, missing relation) before the run was changed.
  pest 1384 passed / 3003 assertions · pint passed · phpstan 0 errors · console gate 48 passed
  (13th month report added) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 78** * **OS-01 — payables on screen**, and a defect found on the way that mattered more than
  the task. Client: "some features don't have CRUD, example on finance".
  **The defect: an approval given on screen never reached its document.** The inbox called ApprovalRouter, which
  records a signature on the approval step and nothing else. What the signature MEANS — a purchase order becoming
  approved once its last step is signed, a requisition becoming returned and releasing the budget it claimed —
  lives in each document's own service, and the inbox never called it. So every document approved from the panel
  stayed "submitted" for ever and the chain behind it refused to start. Every existing test approved through the
  services, which is exactly why 1,384 passing tests never saw it. Fixed with `ApprovalDecisions`, which routes a
  decision to the document's own service and falls back to the router for types that have none; the authority check
  still runs once, inside the service's transaction, so signature and status commit together or not at all.
  **The AP voucher had no life past "raised".** `ApVoucherStatus` has carried APPROVED, PAID and CANCELLED since the
  table was created and `config/approvals.php` has routed `ap_voucher` through the matrix since Phase 1 — nothing
  ever set them. PayablesService gains submit, approve, returnForRevision, pay and cancel. **Submission routes on
  NET**, not gross: the withholding never leaves and the advance left earlier, so routing on gross would send a
  payment up a tier it does not belong in. **Paying requires the bank reference** — without it nothing reconciles
  the voucher to the statement — and paying twice is refused, which is the defect the refusal exists for. **Paid is
  final**: a vendor credit note corrects it, not an edit. **Cancelling hands back the advance it recovered**,
  otherwise the advance stays marked recovered against a payment nobody will make: invisible to the next voucher and
  never collected.
  **Screens.** Three-way matches gained "Match an invoice" — the clerk types only what the invoice says, and the
  order, receipt and accepted quantity are read from the documents, because a typed accepted quantity is exactly
  what a short delivery hides behind. The short-delivery refusal now reaches the screen as a persistent message and
  writes nothing. AP vouchers gained "Raise a voucher" plus submit, record-payment and cancel as row actions, each
  offered only in the state it belongs to. **Vendor advances gained a register**, which they never had — the payment
  an auditor opens first, with outstanding-per-order and a total.
  `matches()`, `payables()` and the voucher builders moved from a test file into `ProcurementFixtures`, per the rule
  that a suite must not depend on which neighbour happens to load first.
  Also recorded: BUILD-STATE now carries the OS-00..OS-10 table and the correction that 29 of 40 screens were built
  list-only, which PLAN.md never asked for — it chose Filament FOR "resource CRUD, form builder, approval-style
  Actions". The real rule, unchanged, is that every write goes through the domain service.
  pest 1414 passed / 3102 assertions · pint passed · phpstan 0 errors · console gate 49 passed
  (vendor advances added) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 79** * **OS-02a — the contract on screen.** It had no service and no screen at all: factories
  and seeders only. That made the system unusable from a clean install, and not in a subtle way — **F14 refuses a
  purchase requisition on a project with no SIGNED contract**, so nobody could spend; and the billing schedule that
  every milestone bills against is built from the contract sum, so nobody could bill either.
  **Signing builds the billing schedule in the same transaction.** A signed contract with no schedule is a project
  that can spend but cannot bill, and nothing would have said so until the first billing was attempted. If the
  contract type has no configured milestones the whole signing is refused rather than leaving a signed contract
  nobody can bill against — a test proves the contract is still "for signature" afterwards.
  **The terms are fixed once the contract leaves draft.** Milestone amounts are percentages of the sum, retention is
  withheld from every invoice as a fraction of it, and the defects liability period dates the warranty and the
  retention release. Editing any of them later silently restates money already billed, withheld and released, so the
  form disables them and the service refuses them.
  **Retention is a fraction, not a percentage**, and the refusal says so: 10 means "ten per cent" to a person and
  "ten times the invoice" to the calculation. Caught beside its own field.
  **Milestone documents can finally be attached** (F4). A billing is refused while any required document is missing,
  and there had been no way to record one — so the first billing of every project was unreachable. The table shows
  every missing document at once, not one per round trip, and only the keys that milestone actually asks for are
  offered: any other key satisfies a count while the evidence stays absent.
  Rows follow the project scope, so a project manager sees the contracts of the jobs they are on and gets a 404 on
  anybody else's.
  pest 1436 passed / 3179 assertions · pint passed · phpstan 0 errors · console gate 50 passed
  (draft contract form added) · daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 80** * **OS-02b — the acquisition-to-cash chain on screen.** Measure, survey, bill, approve or
  return, invoice, collect, chase: every service built in Phase 2 and none of it startable from the panel.
  **The refusals are the point, and each one now reaches the screen.** A billing whose milestone documents are
  incomplete (F4) is refused with the whole list at once, so the QS makes one trip instead of one per document. A
  measurement that FALLS with no reason is refused — a re-measurement is legitimate, a percentage that quietly drops
  is the one nobody can account for at close-out. A collection larger than the invoice is refused, because
  over-collection is almost never generosity: it is a payment belonging to another invoice, and it hides there until
  a reconciliation finds it.
  **The joint survey is two separate signatures**, contractor then client, with both representatives named before
  either signs — the invoice rests on those signatures, and one nobody can attribute is not evidence.
  **Returning a billing carries the deducted lines.** The reason is required (it is what tells the QS what to
  re-measure) and each struck-out line is blocked from the next submission until somebody clears it deliberately —
  slide 6's branch, which is otherwise just a rejection.
  **An AR escalation is closed by saying what was done**, not by ticking it: "chased the client" and "the client
  disputes the retention" lead to different next steps, and the next sweep re-raises anything still outstanding.
  Two relations were added for the screens to read through — `BillingMilestone::billings()` (so only unbilled
  milestones are offered) and `Billing::invoice()` (so one billing cannot be invoiced twice).
  **Two self-inflicted breaks, both recorded because only one of them announced itself.**
  PHPStan caught the first: inserting `invoice()` above `lines()` orphaned `lines()`'s generic docblock, and two
  older tests lost their type inference at once. The annotation went back where it belongs.
  The second announced nothing and is the more dangerous kind. The new suite was written to
  `tests/Feature/Billing/BillingScreensTest.php` — **a file that already existed**, from P6-01 — and overwrote six
  tests (sixteen with their datasets). Every check still reported green: the suite passed, the console gate passed,
  the log window was clean. The only symptom was the TOTAL, which fell from 1,436 to 1,430 when ten tests had just
  been added. Caught by reading the count rather than the word "passed", confirmed against `git show HEAD:`, restored
  with `git checkout --`, and the new suite renamed `BillingChainScreensTest`. **A green suite with fewer tests in it
  is not a green suite**, and nothing in the check list would have said so.
  pest 1446 passed / 3238 assertions · pint passed · phpstan 0 errors · console gate 50 passed ·
  daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 82** * **Procurement, warehouse, payroll and OPEX made operable; the KPI dashboard built; and
  the P&L page rebuilt after actually looking at it.**
  **Warehouse mattered most**, because it unblocked something already shipped: a three-way match needs an
  INSPECTION, so the payables screens from OS-01 could not be reached in a clean system at all. Receive, inspect line
  by line, return the rejects, take only the ACCEPTED quantity into stock, issue against a cost code, count.
  **Payroll** gained certify/reject on a DTR, overtime approval with its written reference, and the run's own acts —
  compute, explain a variance, approve, prepare and transmit payment. **OPEX** gained the month's stages and the
  variance explanation that blocks a close. **Procurement** gained the whole head of the chain plus a subcontracts
  register that never existed, which had left F6 back-charges unreachable.
  **The dashboard** (OS-14): five widgets on what was a blank page for every role. Margin reads "Not yet billed"
  rather than 0% — zero margin on zero revenue says the company worked for nothing. Nothing is cached: a KPI that
  drifts from the ledger it summarises is worse than none. A widget is access-controlled like any screen, and a new
  test fails the build if any registered widget has no rule.
  **The P&L page, and the lesson.** The client called the design ugly. It was worse than ugly: **Filament ships a
  PRECOMPILED stylesheet containing only the classes Filament itself uses**, so every Tailwind utility written in a
  custom page — `grid-cols-2`, `text-end`, `py-1` — was absent and did nothing. The page rendered as raw HTML flow:
  no grid, no alignment, figures stacked under their labels. It had been written and shipped **without once looking
  at it**, with Playwright available the whole time.
  Rebuilt with real CSS scoped to the page, drawn from Filament's own custom properties so the palette and dark
  theme stay right without adding a build step for one screen. Screenshots then found two more faults: the
  profit/loss colours never applied (a cascade collision of my own — `.pnl-table td` and `.pnl-summary .pnl-figure`
  outranked `.pnl-pos`, so the variable resolved to green and the text still came out black), and a negative cash
  requirement printed as a minus figure when it is a SURPLUS, which is the one thing a reader misses in a column of
  numbers.
  **The page also had no way to change its period.** It computed a month on mount and offered no control, so the
  question it exists to answer — "how did we do in March?" — could not be asked. Previous/next stepping and a month
  picker were added; a restyle alone would have left that in place.
  Two older tests asserted on wording the redesign changed, and a console test for this page already existed — I had
  added a second. Both corrected. **Check for existing coverage before writing new**, and **look at the screen you
  changed.**
  pest 1507 passed / 3436 assertions · pint passed · phpstan 0 errors · console gate 52 passed ·
  daily logs 0 new ERROR/CRITICAL.
- 2026-09-14 * **iter 83** * **Staging exists: https://cst.app-staging.site** — on the client's Hostinger Business
  (shared) plan, at their instruction. First time the application has run anywhere but this machine.
  **Verified on the server, not assumed:** 116 of 116 migrations ran, 0 pending. **Both ledger immutability triggers
  are present** (`project_cost_ledger_no_update`, `_no_delete`) — the invariant most at risk, because the host runs
  **MariaDB 11.8, not the MySQL 8.4 every test ran against**; `DB_CONNECTION=mariadb` (Laravel's own driver), binary
  logging off so ERROR 1419 did not arise. Demo seed: 10 accounts, 1 project, 4 ledger rows; the admin credential was
  checked against the stored hash on the server. From outside: `/admin/login` 200, and `/.env`, `/vendor/…`,
  `/composer.json` and logs all refused. Scheduler and queue each run once by hand and succeeded; `proc_open` is
  available, so scheduled commands can launch.
  **The web root is the application folder** on this plan (FTP is jailed to it), so without intervention `/.env` —
  database password and APP_KEY — would have been downloadable. A root `.htaccess` routes every request into
  `public/` and refuses dotfiles; it was **proven with canary files before any code was uploaded** (public canary 200,
  root canary 404, `/.env` 403), and re-checked after extraction.
  **Host differences handled:** default CLI `php` is 8.2, so every command uses the host's PHP 8.3.33 binary
  (`bcmath` present). `exec()` is disabled, so `storage:link` failed (the one ERROR in the day's log) and the symlink
  was created by hand. No Redis: queue and cache run on the database. No SSH crontab: the scheduler and a per-minute
  `queue:work --stop-when-empty` must be added in hPanel by the client. Access was by an SSH key generated for this
  deploy; no password was used.
  **What this does NOT satisfy, stated so nobody reads staging as the gates closing:**
  (1) The phase gates require the flow to run on staging **deployed by push**. This was deployed by FTP upload and an
  SSH setup script — `deploy.sh` and CI were not exercised. (2) DEPLOY.md disqualifies shared hosting **for
  production** (no Supervisor: a payroll run approximated by a per-minute cron can die mid-run); it stays disqualified.
  (3) The monthly `backup:rehearse` will **fail** here — it restores onto a scratch database this plan cannot create —
  and `ops:job-failures` will then alert on it. (4) Tests have never run against MariaDB; the migrations applying
  cleanly is evidence, not a suite.
  **The client should rotate** the database, FTP and SSH passwords shared in chat during this deploy.
