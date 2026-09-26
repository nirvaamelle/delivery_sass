# Construction ERP — Phase Plan

> **Companion to `PLAN.md`.** That document settles *what we are building and on what stack*.
> This one is the slide-by-slide reading of the deck, the gaps that reading exposed, and the
> phase-by-phase delivery plan with entry and exit criteria.
>
> **Source:** `concept/Construction-Operations-MBI.pptx` — 12 slides, read in full.
> **Reviewed:** 2026-09-10. **Status:** building — Phase 0 in progress, see `BUILD-STATE.md`.
> **Stack:** PHP 8.3 / Laravel 13 / Filament 4.13 / MySQL 8 on a Ploi-managed VPS (see `PLAN.md` §2).

---

## How to read this

- **Part A** reviews each of the 12 slides: what it specifies, what it obligates in the build,
  and what it exposes that `PLAN.md` does not yet cover.
- **Part B** collects those exposures as findings **F1–F18**. Each names the slide it came from
  and what has to change.
- **Part C** is the phase plan. Every phase has an entry gate, a deliverable set, an exit gate,
  and the findings it must close.
- **Part D** is the decisions that are still the client's to make.

Findings are referenced by number throughout. Nothing in Part C is scheduled without a slide
behind it.

---

# Part A — The deck, slide by slide

## Slide 1 — Title: four phases, four chains

**Specifies.** The frame for everything else: four project phases (`Project Acquisition` →
`Pre-Construction` → `Construction` → `Post-Construction`) and four process chains, with
"owners, forms and approval gates defined at every step."

**Obligates.** Three axes exist in the data model from day one, and every document sits at a
known point on all three: which chain it belongs to, which phase it runs in, and who owns it.
`projects.phase` is an enum of exactly these four values. Every document type carries an owner
role, which is what the approvals inbox routes on.

**Finding.** None — this is the frame `PLAN.md` §1 already adopts.

---

## Slide 2 — The process map: chains against phases

**Specifies.** A 4×4 grid. Each cell lists the steps of one chain within one phase. The closing
line is a hard rule: *"Each cell is a gated step: no step starts without the approved input from
the step before it."*

Reading the grid column by column, rather than chain by chain:

| Chain | Phase 1 | Phase 2 | Phase 3 | Phase 4 |
|---|---|---|---|---|
| **1 · Acquisition to cash** | NOA, PR against budget, subcon bidding, PO | Contract signing, mobilization plan, billing schedule locked | 30% downpayment, progress billing to 100%, invoice and OR | Final billing, punchlist, retention release, close-out docs |
| **2 · Material and vendor** | Vendor/subcon accreditation, RFQ to three, bid tabulation and award | BOM → PR, price agreements, delivery schedule | PO release and delivery, receiving/inspection/issuance, three-way match | Final reconciliation, warranty turnover, vendor scorecard |
| **3 · HRIS and payroll** | Manpower plan and rates approved | Onboarding, 201 file, biometric enrollment, cost centre assignment | Daily timelogs, OT approval, payroll run, labor cost posted | Demobilization, final pay and clearance |
| **4 · OPEX** | OPEX budget allocated per project | Opening balances, petty cash, COA mapped | Expense capture and coding, monthly consolidation, budget vs actual | Final OPEX close, project P&L |

**Obligates.** Every chain has work in every phase. Nothing can be built as "the phase 3 module."

**Finding — F1.** `PLAN.md` §1 states chain 2 runs "2 → 3", chain 3 runs "2 → 3", and chain 4
runs "3 → 4". The grid contradicts all three. Vendor accreditation and the first bid award are
phase-1 activities; the manpower plan is phase 1 and final pay is phase 4; the OPEX budget is
allocated in phase 1 and closed in phase 4. **All four chains run 1 → 4.** This is not
cosmetic: it means vendor accreditation, the manpower plan and the OPEX budget allocation must
all exist before the first project reaches Pre-Construction, which moves them earlier in the
build than `PLAN.md` §6 has them.

---

## Slide 3 — Phase 1: seven gated steps, award to first collection

**Specifies.** Seven steps, each with its sub-steps, its output document and its owner:

| # | Step | Output | Owner |
|---|---|---|---|
| 1 | Project awarded | Signed contract, NTP, project code | Business Dev / PMO |
| 2 | Purchase requisition | Approved PR, budget clearance | Site Engineer / Procurement |
| 3 | Vendor / subcon award | Bid tabulation, notice of award | Procurement / Technical |
| 4 | PO creation | Approved PO, subcontract | Procurement / Finance |
| 5 | **Operations mobilization** | **Mobilization checklist** | **Operations / HR** |
| 6 | Progress billing | Billing form, accomplishment report | QS / Finance |
| 7 | Invoice and receipt | Sales invoice, official receipt | Finance / Accounting |

Three gate rules are stated outright: *no PO without an approved PR and a tabulated bid; no
mobilization without a countersigned PO; no billing without a verified statement of
accomplishment.* Retention is released at close-out.

**Obligates.** These seven outputs are seven document types with numbering, an approval route,
and a link to the document before them. Step 4's "countersigned by vendor" is a state on the PO,
not a separate document — a PO can be *approved* internally and not yet *countersigned*, and the
mobilization gate tests the second, not the first.

**Finding — F2.** Step 5 has no home in `PLAN.md`. The gate ("no mobilization without a
countersigned PO") is listed in §5, but there is no `mobilizations` table, no checklist, and no
`permits` table — despite "site setup and permits secured" being explicit. Mobilization is a
real document with a real owner and needs modelling.

---

## Slide 4 — Phases 2–3: the procurement cycle, PR to payment in nine steps

**Specifies.** Nine steps: PR → canvass/RFQ → bid tabulation → approval → PO issuance →
delivery and receiving → inspection and QC → warehouse and issuance → match and payment.
Owners run Site Engineer → Procurement → Finance/Management → Warehouse/Site → QC/Technical →
Warehouse → Accounting/Treasury.

Controls stated: three quotes minimum, PO before delivery, no payment without a three-way match,
accreditation renewed yearly, scorecards on price, on-time delivery and rejection rate.

Details easily missed on a first read:
- Step 5: *"Downpayment released if required"* — money can leave before anything is delivered.
- Step 6: short **or over** delivery noted on the DR.
- Step 8: stock card **and bin location**; physical count every month end.
- Step 9: AP voucher raised **with tax documents**.

**Obligates.** This is the highest-volume chain and the one `PLAN.md` §6 correctly builds first.
The nine steps map almost exactly onto its §4 procurement tables.

**Finding — F8.** A vendor downpayment at step 5 cannot be three-way matched — there is no
receiving report yet. If the only path to payment is the three-way match gate, downpayments are
impossible to record. Needs a vendor advance concept, offset against the voucher when the goods
arrive.

**Finding — F9.** "Tax documents" appear on both the AP voucher here and the sales invoice on
slide 6. In the assumed jurisdiction that means BIR withholding — expanded withholding tax on
supplier payments, and creditable withholding on client collections. Neither is in `PLAN.md` §4,
and withholding changes the amount actually paid and collected. It cannot be bolted on after the
ledger exists.

---

## Slide 5 — Phase 2: approval authority and accreditation

**Specifies.** The four-tier approval matrix, deliberately shown as bands rather than amounts:

| Tier | Documents required | Approver | Turnaround |
|---|---|---|---|
| 1 — low value | PR, single canvass sheet | Site Engineer → Procurement Head | 1 working day |
| 2 — routine | PR, three quotes, abstract of canvass | Procurement Head → Finance Manager | 2 working days |
| 3 — major | PR, three quotes, tabulation, spec sheet | Finance Manager → COO | 3 working days |
| 4 — capital or subcontract | Full bid package, contract and legal review | President or Board | 5 working days |

Plus three panels. **Accreditation file:** business registration and tax certificate, local
permit and tax clearance, bank details and authorised signatory, **shop or plant validation
visit**, **insurance and surety bond for subcontractors**, renewed every twelve months.
**Scorecard:** on-time delivery rate, rejection rate at inspection, price against last canvass,
**completeness of documents** — rated after every PO, reviewed quarterly. **Removal triggers:**
two late deliveries in one quarter, rejected volume above five percent, falsified documents
(immediate), **unresolved warranty claim**, *"suspended until cleared by management."*

Sole source skips canvassing only with written justification approved one level above the normal
approver.

**Obligates.** Each tier requires a *different document set*, so the approval matrix stores not
just who approves but what must be attached before the route opens. Turnaround is a per-tier SLA
that the cycle-time dashboard measures against.

**Finding — F11.** Suspension is a distinct state from removal — "suspended until cleared by
management" is reversible, removal is not, and clearing needs a named authority. `PLAN.md` §5
routes both outcomes to "vendor status" without defining the states or the clearing path.
And **"unresolved warranty claim" is missing entirely from `PLAN.md` §5's trigger list.**

**Finding — F12.** The deck disagrees with itself on the scorecard. Slide 4 names three
dimensions — price, on-time delivery, rejection rate. This slide names **four**, adding
completeness of documents. `PLAN.md` has `vendor_scorecards` but never enumerates the
dimensions, so the conflict is currently unresolved rather than resolved wrongly. Take slide 5's
four as authoritative and confirm. Cadence is specified twice over: rated after every PO,
reviewed quarterly.

**Finding — F15.** The accreditation file includes a **plant or shop validation visit** — a
dated site-visit record with a verdict, not a document upload — and, for subcontractors,
**insurance and a surety bond**. Bonds expire on their own schedule, independent of the
twelve-month accreditation clock. That is a second expiry to track, and an expired bond should
block a subcontract award the way an expired accreditation blocks an RFQ.

---

## Slide 6 — Phase 3: progress billing and collection

**Specifies.** Five milestones, each with its own trigger and its own required documents:

| Milestone | Trigger | Documents required |
|---|---|---|
| 30% downpayment | Contract signed, NTP issued | Billing form, signed contract, invoice request |
| 50% progress | Verified accomplishment reaches 50% | Accomplishment report, joint survey, photos |
| 75% progress | Verified accomplishment reaches 75% | Accomplishment report, updated S-curve |
| 95% pre-final | Substantial completion, punchlist issued | Punchlist, draft as-built drawings |
| 100% final | Punchlist cleared, turnover accepted | Certificate of completion, warranty, clearances |

Retention of 10% is withheld on every billing, released after the defects liability period.

The submission branch: billing submitted → client evaluation → **approved** or **returned**.
Approved runs sales invoice → collection follow-up → official receipt. Returned goes back to the
QS for re-measurement and is resubmitted in the next cutoff, with the deduction and its reason
logged so the same item is not billed twice.

Two rules in the small print: **billing cutoff is monthly**, and every submission needs a joint
accomplishment survey signed by the client representative before an invoice can be raised.
Collection follow-up: **AR aging reviewed weekly; unpaid billings escalated to the project
manager at 30 days.**

**Obligates.** The returned branch is a first-class path, not an error case. A returned billing
keeps its number, gains a reason, and its deducted lines stay blocked from the next submission.

**Finding — F4.** Each milestone requires a *different* document set, exactly as each approval
tier does. `PLAN.md` has `billing_milestones` but nothing storing what must be attached before
submission opens. Without it, "no billing without a verified statement of accomplishment" is the
only gate enforced and the other four document requirements are honour-system.

**Finding — F13.** AR escalation to the project manager at 30 days is a specific, scheduled
action with a named recipient. `PLAN.md` has an AR aging sweep but not the escalation rule.

**Finding — F3.** This slide sets a **monthly** billing cutoff. Slide 7 sets a **semi-monthly**
payroll cutoff. Slide 8 sets an OPEX cutoff on **day 26**. These are three different calendars
that all feed one ledger. `PLAN.md` §4 has a single `cutoff_calendar` table, which cannot
represent them. It needs a cutoff type and per-project override, because "nothing books after
the cutoff date" (§5) is meaningless until you say *which* cutoff.

---

## Slide 7 — Phases 2–3: HRIS, payroll and timelogs

**Specifies.** Nine steps: manpower request → hiring and onboarding → HRIS enrollment → daily
timelogs → validation and OT approval → payroll processing → review and approval →
disbursement → labor cost posting. Owners run Site/Operations → HR → HR/IT → Timekeeper →
Site Engineer → HR/Payroll → HR/Finance → Treasury → Accounting.

Stated rules: cutoffs are 1–15 and 16–end of month; DTR closes one day after cutoff; payroll
releases three working days later. *"No timelog, no pay"* — unvalidated days are held and paid
in the next cutoff once the site certifies them. Employment contract signed **before first
shift**. Gross pay computed from **validated DTR only**. OT and night differential approved
**in writing**. Disbursement by bank upload **or cash payout with acknowledgment**.

**Obligates.** The held-day mechanic is subtle: an unvalidated day is not dropped, it is carried.
A DTR line therefore has a state and can belong to a payroll period later than the one it fell
in, which means `payroll_lines` must be able to reference a DTR line from a prior period.

**Finding — F10.** Step 7 requires *"variance against last cutoff explained per project"* before
the payroll register is approved. `PLAN.md` §5 has a variance-explanation gate for the OPEX
month-end close but not for payroll. This is a second, independent variance gate.

---

## Slide 8 — Phase 3: OPEX consolidation on a fixed calendar

**Specifies.** A seven-stage monthly calendar:

`Day 1–25 capture` → `Day 26 cutoff` → `Day 27 coding` → `Day 28 validation` →
`Day 29 consolidation` → `Day 30 budget review` → `Day 3–5 reporting` *(the following month)*

Day 26 carries a rule beyond the cutoff itself: cash advances are liquidated **or charged to the
next payroll**. Day 30: variance above 10% explained in writing.

**Feeds the consolidation:** labor cost from the payroll register; materials and subcontracts
from AP; site petty cash and revolving fund; utilities, rentals and permits; **equipment fuel,
repair and depreciation**; insurance, bonds and IT services.

**Produces:** OPEX per project against budget; cost per unit of accomplishment; variance report
by cost centre; **cash requirement for the next month**; input to project P&L; forecast cost to
completion.

Control: an expense with no receipt, no cost code, or no budget line is not booked — returned to
the site the same day, and **cannot be charged to the project later**.

**Obligates.** The calendar is a state machine driven by the scheduler, as `PLAN.md` §6 says.
The "cannot be charged later" clause means a rejected expense is permanently barred from that
period, not merely bounced.

**Finding — F5.** Equipment fuel, repair and depreciation is a named input to every monthly
consolidation, and slide 9 requires equipment, tools and IT assets to be returned and logged at
demobilization. **`PLAN.md` §4 has no asset or equipment register at all.** Depreciation cannot
be posted monthly without one, so a named OPEX input is currently unbuildable.

**Finding — F16.** "Cash requirement for the next month" is a required output. `PLAN.md` §4's
ledger section produces a P&L, cost per unit and forecast to completion, but no cash requirement.

**Finding — F17.** Cash advances unliquidated at day 26 are charged to the next payroll. That is
a cross-chain write from OPEX into HRIS, on a schedule, and it is the kind of link that silently
does not get built when the two chains are delivered in different phases.

---

## Slide 9 — Phase 4: post-construction close-out

**Specifies.** Six steps: substantial completion → punchlist clearing → turnover and acceptance
→ final billing → demobilization → project close-out. Owners run Project Manager → Site/QC →
PMO/Client → QS/Finance → HR/Operations → Accounting/PMO.

Three closing panels. **Documents to close:** as-built drawings and manuals, certificate of
completion, warranty certificates and permits. **Financial close:** final billing and
collection, retention release, final project P&L. **People and assets:** clearance and final
pay, equipment and IT asset return, vendor and subcon scorecards.

Step 2 computes **subcontractor back-charges**. Step 4 applies deductions and back-charges
**before** the invoice. The closing rule: a project stays open in the books until retention is
collected, and the close-out report names who cleared each item and when.

**Obligates.** Close-out is a checklist with named clearers per line, not a status flag. Final
billing depends on back-charges being computed first, which makes step 2 a hard predecessor of
step 4 across two different chains.

**Finding — F6.** Subcontractor back-charges appear in `PLAN.md` §6 Phase 5 prose but have no
table in §4. They reduce a subcontractor's payable and increase project cost — they are ledger
postings, and they gate the final billing amount.

**Finding — F7.** Warranty certificates are collected at turnover, and an *unresolved warranty
claim* suspends a vendor (slide 5). So warranties need a register that links the certificate to
the vendor and to any claim raised against it. `PLAN.md` has neither the warranty register nor
the claim.

---

## Slide 10 — The document handoff spine

**Specifies.** Every chain's document sequence, all four converging on one place:

| Chain | Sequence | Lands in ledger as |
|---|---|---|
| Acquisition | Signed contract → Billing form → Sales invoice → Official receipt | Revenue and receivables |
| Material and vendor | PR → PO → Receiving report → AP voucher | Material and subcontract cost |
| HRIS | Validated DTR → Payroll register → Payslip → Journal entry | Labor cost |
| OPEX | Receipt and liquidation → Coded expense entry → OPEX schedule → Budget vs actual | Overhead |

**Handoff rule:** every document carries the project code, the cost code, and the reference
number of the document before it — PR on the PO, PO on the receiving report, receiving report on
the AP voucher.

**Where the chains break today:** PRs raised without cost codes, deliveries received without a PO
reference, site expenses booked after the cutoff. *"Each one shows up later as an unexplained
variance in the project profit and loss."*

**Obligates.** This is the single most important slide in the deck and `PLAN.md` §1 is right to
build on it. `document_links` is the spine; the three named breakages become three database
constraints, not three policies.

**Finding.** None. This slide is fully reflected in `PLAN.md`. Its outputs — project P&L, cost
per unit of accomplishment, **cash position and forecast** — reinforce F16.

---

## Slide 11 — Accountability: owner, cycle time, control

**Specifies.** One row per process, each with a trigger, an owner, a cycle time and the single
control that must pass:

| Process | Trigger | Owner | Cycle time | Control |
|---|---|---|---|---|
| Project acquisition | Notice of award | PMO and Business Dev | 5 days, award to mobilization | **Contract signed and budget opened before any PR** |
| Purchase requisition | Approved BOM or site need | Site Engineer | 1 working day | Cost code and budget availability confirmed |
| Vendor and subcon award | Approved PR | Procurement | 3–5 working days | Three quotes and an abstract of canvass |
| Delivery to payment | PO issued | Warehouse and Accounting | Per PO terms, 30 days typical | PO, receiving report and invoice match |
| Progress billing | Monthly accomplishment cutoff | QS and Finance | 5 working days after cutoff | Joint survey signed by the client |
| Payroll | Semi-monthly cutoff | HR and Treasury | 3 working days after cutoff | Validated DTR and written OT approval |
| OPEX consolidation | Month end | Accounting | Close by day 30, report by day 5 | Coded receipts within the cutoff only |

Cycle times are measured from trigger to signed output. Missed targets are reported at the weekly
operations meeting with the reason and the affected project.

**Obligates.** Every document records `triggered_at` and `signed_output_at`, as `PLAN.md` §5
says. The weekly meeting is the consumer, so the dashboard needs a *this week's misses* view
with reason and project, not just an average.

**Finding — F14.** The acquisition control is *"contract signed and budget opened before any
PR"* — a **project-level** precondition. `PLAN.md` §5 has the line-level check (no PR without a
cost code and budget) but not the project-level one. A PR against a project whose contract is
unsigned should be impossible regardless of how good its cost code is.

---

## Slide 12 — Forms, systems and next steps

**Specifies.** The forms in play per chain, and the systems they touch today:

| Chain | Forms | Systems today |
|---|---|---|
| Project acquisition | Billing form, invoice request, statement of accomplishment, OR | PMO tracker and accounting system |
| Material and vendor | PR form, RFQ, abstract of canvass, PO, receiving report, RTV slip | Procurement log and inventory module |
| HRIS and payroll | Manpower requisition, 201 checklist, DTR, overtime slip, payroll register, payslip | Biometrics and HRIS payroll module |
| OPEX | Liquidation form, expense coding sheet, budget vs actual report | Accounting system and expense ledger |

Six decisions needed: peso limits per tier; billing milestones per contract type; retention rate
and DLP; payroll cutoff and release dates; named owner for each of the seven acquisition steps;
which forms already exist in the system and which are still manual.

A four-step rollout: **CIRCULATE → CONFIRM LIMITS → ISSUE AS SOP → AUDIT ONE CYCLE.**

The closing note matters for scope discipline: the flows follow the sequence the client gave;
everything added — thresholds, cutoffs, retention, three-way match — is a standard construction
control *"shown so the team can confirm or replace it with your own rule."*

**Obligates.** Every threshold in this system is configuration, never a constant. The client is
expected to overwrite the defaults.

**Finding — F18.** The deck's own next-steps track is absent from `PLAN.md`. The process must be
circulated, its limits confirmed, issued as SOP, and **audited over one full cycle** — and that
is organisational work running in parallel with the build, not after it. Building an approval
engine before the peso limits are confirmed means building against placeholders; auditing one
cycle before go-live is what proves the flows survive contact with the site.

---

# Part B — Findings

Eighteen findings. Severity is about cost-to-fix-later, not difficulty.

| # | Finding | Slide | Severity | Closes in |
|---|---|---|---|---|
| **F1** | All four chains run phases 1→4, not 2→3 / 3→4 as `PLAN.md` §1 states | 2 | **High** | Phase 0 |
| **F2** | Mobilization step unmodelled — no checklist, no permits table | 3 | Medium | Phase 2 |
| **F3** | Three distinct cutoff calendars, one table cannot hold them | 6, 7, 8 | **High** | Phase 0 |
| **F4** | Per-milestone required-document checklists have no data behind them | 6 | Medium | Phase 2 |
| **F5** | No equipment/asset register — a named OPEX input is unbuildable | 8, 9 | **High** | Phase 1 |
| **F6** | Subcontractor back-charges have no table; they gate final billing | 9 | Medium | Phase 5 |
| **F7** | No warranty register; needed at turnover *and* for vendor suspension | 5, 9 | Medium | Phase 5 |
| **F8** | Vendor downpayment cannot pass the three-way match gate | 4 | **High** | Phase 1 |
| **F9** | Withholding tax on AP vouchers and sales invoices not modelled | 4, 6 | **High** | Phase 0 |
| **F10** | Payroll register variance vs last cutoff is an unmodelled gate | 7 | Medium | Phase 3 |
| **F11** | Vendor suspension ≠ removal; warranty-claim trigger missing | 5 | Low | Phase 1 |
| **F12** | Deck contradicts itself — scorecard has three dimensions on slide 4, four on slide 5 | 4, 5 | Low | Phase 1 |
| **F13** | AR escalation to PM at 30 days not modelled | 6 | Low | Phase 2 |
| **F14** | Project-level gate — contract signed and budget opened before any PR | 11 | Medium | Phase 0 |
| **F15** | Accreditation needs a validation-visit record and a bond expiry clock | 5 | Medium | Phase 1 |
| **F16** | "Cash requirement for next month" is a required output, not produced | 8, 10 | Low | Phase 4 |
| **F17** | Unliquidated cash advances charge to next payroll — cross-chain link | 8 | Medium | Phase 4 |
| **F18** | The deck's SOP rollout track is missing from the plan entirely | 12 | **High** | Track B |

**The four that change the schema and must land in Phase 0:** F1, F3, F9, F14. All four are
cheap now and expensive after the ledger has rows in it — F9 especially, since withholding
changes every amount that moves.

---

# Part C — The phase plan

Two tracks run in parallel. **Track A** is the build. **Track B** is the deck's own rollout
(F18). Track B gates parts of Track A, which is why it is here and not in an appendix.

Fourteen weeks, unchanged from `PLAN.md` §6 — the findings redistribute work rather than adding
to the total, with the exception of the asset register (F5), which is genuinely new scope.

---

## Track B — Process rollout (runs weeks 1–14, client-owned)

| Step | What happens | Needed by | Blocks |
|---|---|---|---|
| **B1 · Circulate** | Deck goes to the seven process owners for comment | Week 1 | — |
| **B2 · Confirm limits** | The ten decisions in Part D answered and signed | **Week 2** | Phase 1 approval routing |
| **B3 · Issue as SOP** | Flows issued as the standard process, owners named | Week 6 | Phase 3 onward |
| **B4 · Audit one cycle** | One full month audited against the SOP on paper | Weeks 10–12 | Go-live |

**B2 is the hard dependency.** The approval matrix is configuration, but the *shape* of the
routing — two approvers per tier, escalation one level above for sole source — has to be right
before Phase 1 builds against it. If B2 slips past week 2, Phase 1 builds against the deck's
bands as placeholders and a re-test is needed when the real numbers land.

---

## Phase 0 — Foundation (week 1)

**Entry:** repository, Laragon environment, VPS provisioned via Ploi, staging subdomain resolving.

**Build.** Laravel 13 + Filament 4.13 skeleton. spatie permission, activitylog, medialibrary.
Organization, project, cost-code and budget models. The four cross-cutting services from
`PLAN.md` §3 — **Numbering, Gates, Approvals, Posting** — built first and built once. Approval
matrix admin. The unified approvals inbox shell.

**Findings closed here — all four schema-level ones:**

- **F1** — `projects.phase` and every chain's tables exist from the start; no chain is scoped to
  a phase subset. Correct `PLAN.md` §1's chain-span table.
- **F3** — `cutoff_calendar` gains a `cutoff_type` (`billing` monthly · `payroll` semi-monthly ·
  `opex` day-26) with per-project override. The Posting service takes the cutoff type as an
  argument; "nothing books after cutoff" resolves against the right calendar.
- **F9** — Tax columns on `ap_vouchers` and `sales_invoices` from the first migration:
  withholding rate, withheld amount, certificate reference. `DECIMAL(18,4)` like all money.
- **F14** — Project-level gate in the Gates service: no PR against a project without a signed
  contract and an opened budget.

**Exit gate.** A PR can be raised, routed through a two-tier approval, and rejected by the
budget check — end to end, on staging, deployed by push. Nothing else ships until this holds.

---

## Phase 1 — Procurement chain (weeks 2–4)

Highest daily volume, so it proves the model. Slides 4 and 5 are the specification.

**Entry:** Phase 0 exit gate passed. **B2 answered** — or the deck's bands accepted explicitly
as placeholders, with a re-test scheduled.

**Build.** PR → RFQ → canvass → tabulation → tiered approval → PO → DR → receiving → inspection
→ issuance → three-way match → AP voucher. Vendor accreditation and scorecards. First ledger
postings.

**Findings closed:**

- **F5** — Asset and equipment register: `equipment`, `equipment_assignments`,
  `equipment_costs` (fuel, repair), `depreciation_schedules`. Built here rather than in Phase 4
  because equipment is *acquired* through procurement, and retrofitting an asset register under
  a live PO chain is worse than building it alongside.
- **F8** — Vendor advances: `vendor_advances`, released against a PO, offset at the AP voucher.
  The three-way match gate accepts an advance as a distinct, non-matched payment type.
- **F11** — Vendor status becomes an enum (`accredited` · `suspended` · `removed`) with a reason
  and a clearing user. Warranty-claim trigger added to the suspension rules.
- **F12** — Scorecard gains document completeness as a fourth dimension. Rated per PO,
  aggregated quarterly.
- **F15** — `vendor_validation_visits` (dated, with verdict) and `vendor_bonds` (independent
  expiry). Expired bond blocks a subcontract award; expired accreditation blocks an RFQ.

**Exit gate.** One full cycle on staging: PR through AP voucher, with a sole-source purchase
escalating one level, an expired-accreditation vendor rejected from an RFQ, and a short delivery
noted on the DR. **Material cost appears in the ledger.**

---

## Phase 2 — Acquisition to cash (weeks 5–6)

Slides 3 and 6.

**Entry:** Phase 1 exit gate passed.

**Build.** Contract, billing schedule, accomplishment and joint survey, billing with the
approved-or-returned branch, sales invoice, official receipt, AR aging, retention ledger.

**Findings closed:**

- **F2** — `mobilizations` with a checklist, plus `permits`. Gated on a countersigned PO, which
  means the PO's countersignature is a state, not an attachment.
- **F4** — `milestone_document_requirements`: the required document set per milestone from slide
  6, checked before submission opens. Configurable per contract type, since Part D item 2 may
  change the milestones themselves.
- **F13** — AR aging sweep escalates unpaid billings to the project manager at 30 days, through
  the notification inbox.

**Exit gate.** A 30% downpayment billing and one 50% progress billing post; one billing is
returned, re-measured, and resubmitted in the next cutoff without the deducted line reappearing.
**Revenue and receivables appear in the ledger.**

---

## Phase 3 — HRIS and payroll (weeks 7–9)

Slide 7. The longest phase, and the one with the most sensitive data.

**Entry:** Phase 2 exit gate passed. **B3 issued** — payroll rules must be the SOP's rules, not
the deck's defaults, before the first real cutoff.

**Build.** Employee 201, manpower requisition, timelog CSV import from biometrics, DTR
validation, OT approval, queued payroll run, register review, payslip PDF, disbursement file,
labor cost posting. Sensitive columns encrypted at rest from the first migration, per
`PLAN.md` §3.

**Findings closed:**

- **F10** — Payroll register variance against the last cutoff, per project, explained in writing
  before approval. Blocks the register, mirroring the OPEX close gate.

**Also settled here:** the held-day mechanic — an unvalidated DTR line carries to the next
period rather than being dropped, so `payroll_lines` can reference a prior-period DTR line.

**Exit gate.** One semi-monthly cutoff runs on staging with an unvalidated day held and paid in
the following cutoff. **Labor cost appears in the ledger.**

---

## Phase 4 — OPEX and the ledger (weeks 10–11)

Slide 8, plus the convergence on slide 10.

**Entry:** Phase 3 exit gate passed. **Part D items 11 and 13 answered** — BIR CAS registration
and payroll headcount both gate this phase, per `PLAN.md` §3.

**Build.** Expense capture, liquidation, coding. The day 1–25 / 26 / 27 / 28 / 29 / 30 / 3–5
calendar as a scheduler-driven state machine. Budget vs actual, variance explanations, month-end
close. Ledger consolidation and project P&L.

**Findings closed:**

- **F16** — Cash requirement for the next month added to the consolidation outputs.
- **F17** — Day-26 job: unliquidated cash advances charge to the next payroll. A scheduled
  cross-chain write, tested explicitly because it is the kind of link that quietly never ships.

**Exit gate.** One month-end close on staging: an expense rejected for a missing cost code and
permanently barred from the period, and a 12% variance blocking the close until explained.
**Overhead appears in the ledger.**

---

## Phase 5 — Close-out and reporting (weeks 12–13)

Slide 9.

**Entry:** Phase 4 exit gate passed. **B4 audit underway** — the paper audit and this phase
should overlap, so audit findings land while the close-out flows are still soft.

**Build.** Substantial completion, punchlist, turnover pack, final billing, demobilization and
clearance, retention release, final P&L, forecast to completion, scorecards filed.

**Findings closed:**

- **F6** — `back_charges` against subcontracts, computed at punchlist clearing, applied to final
  billing before the invoice is raised.
- **F7** — `warranties` register linking certificate to vendor, and `warranty_claims` — which
  feeds the vendor suspension trigger from F11.

**Exit gate.** A project reaches close-out and **cannot be closed** until retention is
collected. The close-out report names who cleared each item and when.

---

## Phase 6 — Hardening and ops (week 14)

**Entry:** all chains posting to the ledger. **B4 audit complete**, findings triaged.

**Build.** Per-project row-level policies. **A backup restored onto a scratch database to prove
it works.** Horizon and failed-job alerting. Log rotation. PHP-FPM and MySQL tuning. fail2ban
and SSH hardening. Indexes checked against five years of simulated document volume.
Activity-log export.

**Exit gate — go-live readiness, all four required:**

1. The §7 demo walk-through runs start to finish on staging, gates rejecting as designed.
2. A backup has been restored onto a scratch database and the restore verified.
3. B4's one-cycle audit is complete and its findings are closed or accepted in writing.
4. 2FA is enforced on every Finance, HR and Admin account.

---

## Critical path

```
B2 confirm limits ─┐
                   ▼
Phase 0 ──▶ Phase 1 ──▶ Phase 2 ──▶ Phase 3 ──▶ Phase 4 ──▶ Phase 5 ──▶ Phase 6 ──▶ go-live
   F1 F3            F5 F8      F2 F4       F10        F16 F17     F6 F7        ▲
   F9 F14           F11 F12    F13                                             │
                    F15                                                        │
                              B3 issue SOP ──────────▶ B4 audit one cycle ──────┘
```

The schedule risk is not the code. It is **B2** — if the peso limits are not confirmed by week 2,
Phase 1 builds against placeholders, and every approval-routing test is provisional until they
land.

---

# Part D — Decisions still open

Items 1–6 are the deck's own (slide 12). Items 7–10 were added by `PLAN.md` §8 from the data
model. Items 11–13 came from the hosting and compliance review. Items 14–17 are new, raised by
this review.

**From the deck (slide 12)**

1. **Peso limits for each of the four approval tiers.** Bands, not amounts, are deliberate.
   → *Blocks Phase 1. This is B2 and it is the schedule's critical path.*
2. **Billing milestones per contract type.** 30/50/75/95/100 is the deck default — fixed, or per
   contract? → *Blocks F4.*
3. **Retention rate and defects liability period.** 10% assumed; DLP length unstated.
4. **Payroll cutoff and release dates.** 1–15 / 16–EOM assumed, DTR closing one day after
   cutoff, release three working days later.
5. **Named owner for each of the seven acquisition steps.** Roles are in the deck; people are
   not.
6. **Which forms already exist in a system and which are still manual.** Decides import versus
   build.

**From the data model (`PLAN.md` §8)**

7. **Existing systems to integrate or replace** — the deck names a PMO tracker, an accounting
   system, a procurement log, an inventory module, a biometrics device and an HRIS payroll
   module. Real products with APIs, or spreadsheets?
8. **Biometric device make and model** — decides CSV import versus live API.
9. **Statutory deductions** — jurisdiction and whose computation tables we follow.
10. **Multi-company or single company?** Decides whether `organizations` is a tenant boundary.
    Expensive to change later.

**From the hosting and compliance review**

11. **Confirm the jurisdiction is the Philippines**, and get the accountant's answer on **BIR CAS
    registration**. → *Gates Phase 4.*
12. **Approve the ~$17/mo production hosting line.**
13. **Headcount processed in payroll** — above 1,000, NPC registration and a designated Data
    Protection Officer become obligations.

**New, from this review**

14. **Withholding tax treatment (F9).** Which EWT rates apply to supplier payments, and is
    creditable withholding expected on client collections? → *Blocks Phase 0.* This is the most
    urgent of the four new items, because it changes every amount that moves through the ledger.
15. **Equipment ownership and depreciation method (F5).** Is equipment company-owned, rented, or
    both? Straight-line, and over what life? → *Blocks Phase 1.*
16. **Vendor downpayment policy (F8).** Which tiers or contract types permit a downpayment before
    delivery, and up to what percentage?
17. **Suspension clearing authority (F11).** "Suspended until cleared by management" — which
    role clears a suspended vendor, and does clearing reset the scorecard window?

---

## What this plan assumes

Stated plainly, so each can be checked rather than discovered:

- The deck is the scope. Anything not on a slide is out until someone puts it on one.
- Every threshold in the deck is a default the client may overwrite — slide 12 says so directly.
- The jurisdiction is the Philippines (item 11 confirms).
- Fourteen weeks is one developer working continuously. The asset register (F5) is genuinely new
  scope against `PLAN.md` §6 and is the first thing to slip if the schedule tightens.
- Track B is client-owned. If it does not run, the software ships against placeholder rules and
  the one-cycle audit that would have caught the mismatch never happens.
