# Decisions Pending

> Placeholders the build is running on because a `PHASE-PLAN.md` Part D decision is unanswered.
> The loop appends here rather than stalling. Every entry is a real value in the codebase that
> the client is expected to overwrite — slide 12 says so directly.
>
> **Grep `PLACEHOLDER: Part D item` in the source to find them all.**

| Part D item | Placeholder in use | Where | Blocks | Added |
|---|---|---|---|---|
| **10** — multi-company or single company | `organizations` is a plain table; every scoped table carries `organization_id`, but no tenant scoping is applied to queries | `organizations`, `projects`, `cost_codes` migrations | Nothing today. Becomes expensive after the ledger has rows | 2026-09-10 |
| **14** — withholding tax rates | EWT `goods` 1% · `services` 2% · `professional` 10% · `rental` 5%, as decimal strings | `config/withholding.php`; columns via the `withholdingColumns()` schema macro | Nothing today. Correcting rates after the ledger has rows means restating postings | 2026-09-10 |
| **3** — retention rate and DLP | Retention `0.100000` (10%, PLAN.md section 5's assumption); defects liability 365 days, which the deck does not state at all | `contracts.retention_rate`, `contracts.defects_liability_days` — per-contract columns, not constants | Phase 2 retention ledger and Phase 5 retention release | 2026-09-10 |
| **1** — peso limits per approval tier (**B2**) | T1 ≤ 50,000 · T2 to 500,000 · T3 to 5,000,000 · T4 unbounded — entirely the build's invention, the deck shows bands only | `ApprovalMatrixSeeder`, rows in `approval_matrix` | **Phase 1 exit gate.** Routing works but routes against numbers nobody has agreed to | 2026-09-10 |
| **5** — named owner per acquisition step | Roles `project-manager` · `procurement-head` · `finance-manager` · `managing-director` · `hr-manager` · `admin`. **Since 2026-09-14 these same placeholder roles also decide which SCREENS each person can open** — payroll to HR and finance, the approval matrix to admin only, and so on | `ApprovalMatrixSeeder` approver_roles; **`config/access.php`** for screen access | Phase 1 approval routing, and now who can see payroll, payables and reporting. Answering item 5 means editing both. **Also since 2026-09-14:** what the four site roles (`timekeeper`, `foreman`, `storekeeper`, `site-engineer`) open follows their job titles, setup screens give projects and budgets to project managers on their own jobs, cost codes to finance, and accounts to admin only; and only a role that sees every project may open one | 2026-09-10 |
| **12** — production hosting line | **Staging now live on the client's Hostinger Business (shared) plan** since 2026-09-14 — MariaDB 11.8, cron-driven queue, deployed by FTP + SSH. **Production is still unprovisioned**: DEPLOY.md disqualifies shared hosting for production (no Supervisor), and staging was not deployed by push | `DEPLOY.md`, `.github/workflows/ci.yml`, `deploy.sh` | **Every phase exit gate still open**: they require the flow on staging *deployed by push*, which needs a host that accepts a git-driven deploy. Decide: a VPS (PLAN.md §2), or accept shared-hosting staging and wire `deploy.sh` to it | 2026-09-10 |
| **2** — billing milestones per contract type | Slide 6's five milestones (30/50/75/95/100) as the `default` contract type, each with its own document set | `config/billing.php` → copied onto `billing_milestones` when a schedule is built | F4 is built; a second contract type is a config entry, not code | 2026-09-11 |
| **15** — equipment ownership and depreciation | Owned, rented and leased all supported; straight-line only; 60-month life default | `equipment.ownership`, `equipment.useful_life_months`, `DepreciationMethod` | F5 depreciation schedules. Per-machine columns, so answering is a data change | 2026-09-11 |
| **17** — suspension clearing authority and AR owner | Clearing user is recorded but any user may clear; AR escalations addressed to `project-manager` | `VendorService::clearSuspension`, `ArAgingService::ESCALATION_ROLE` | F11 clearing permission; F13 recipient | 2026-09-11 |
| **4** — payroll cutoff and release dates | 1–15 / 16–EOM, DTR closing one day after cutoff, release three working days later — the deck's own assumption | `config/payroll.php` → `cutoffs`, `dtr_closes_days_after_cutoff`, `release_working_days_after_close` | **Phase 3.** A misaligned run is refused, so a different cutoff is a config change | 2026-09-11 |
| **9** — statutory deductions (**B3**) | SSS 5% of MSC (5,000–35,000, ₱500 steps) · PhilHealth 2.5% (base 10,000–100,000) · Pag-IBIG 2% (cap 10,000) · BIR semi-monthly graduated table · split evenly across cutoffs · OT +25% · ND +10% · monthly divisor 261 | `config/payroll.php` → `statutory`, premiums, `monthly_divisor` | **Phase 3 exit gate.** These are the build's approximation of published schedules, not an accountant's tables. Wrong here means wrong pay | 2026-09-11 |
| *unnumbered* — scorecard weighting | Straight mean of the four F12 dimensions | `ScorecardService::mean()` | Nothing today; changes every overall score when set | 2026-09-11 |
| *unnumbered* — required permits per project type | Any permit in force satisfies the mobilization checklist | `PermitService::hasAnyValid()` | F2 checklist is weaker than it could be until a required set exists | 2026-09-11 |
| **B4** — one-cycle paper audit against the SOP | Nothing to placeholder: an audit is people reading paper, and the build cannot do it on anybody's behalf. **Not commissioned** as of this entry | Outside the codebase — PHASE-PLAN.md Part C, Phase 5 entry and Phase 6 exit | **Phase 6 exit gate clause 3**, and so go-live. Phase 5 opened without it and Phase 6 cannot close without it | 2026-09-14 |
| *unnumbered* — password policy | At least 12 characters, no composition rules, no expiry — current guidance favours length. Two-factor is built and switched off (`TWO_FACTOR_ENABLED`) at the client's request | `UserAccountService::MIN_PASSWORD_LENGTH` | Nothing today. Applies to accounts created or reset on the Accounts screen; seeded accounts are unaffected | 2026-09-14 |
| *unnumbered* — employee benefits (13th month, service incentive leave, allowances) | **Not a Part D item, so never asked.** SIL: 5 days a leave year after 12 months, year from the hiring anniversary, no carry-over, no cash conversion. 13th month: 1/12 of basic pay + paid leave on approved/released registers in the year, tax-exempt to 90,000, computed and reported but **not paid out** (payout timing is policy). Allowances: amount per cutoff, paid in full for any cutoff in force, taxable into gross (so contributions and tax apply), non-taxable into net. Leave with no worked day in the cutoff is held; leave pay and allowances post to projects in proportion to pay earned | `config/payroll.php` → `benefits`; `AllowanceService`, `LeaveService`, `ThirteenthMonthService`; `PayrollService::writeLine`; `LaborCostPoster::grossByProject` | Pay. Wrong here is somebody's payslip — the same weight as item 9. The payroll officer should confirm before the first live run that carries a benefit | 2026-09-14 |
| *unnumbered* — does a central function read across projects | **`procurement-head` is NOT unscoped** (ProjectScope's list is finance-manager, managing-director, admin), so a buyer must be assigned to a project before they can canvass or order for it. Found 2026-09-14 while building the procurement screens: every canvass test failed until the buyer was assigned. It is defensible — a buyer sees only their own jobs — but it means somebody must put the procurement head on every project, and procurement is usually a central function like finance | `ProjectScope::UNSCOPED_ROLES` | Nothing today; the screens work as built. Answering changes who sees which projects, so it is the client's call and not the build's | 2026-09-14 |
| *unnumbered* — revenue cost code | Revenue posts against the project organization's first cost code | `RevenuePoster::post()` | Revenue reaches the ledger; the chart-of-accounts mapping is the accountant's call | 2026-09-11 |

---

## The two that matter most

**Item 1 — peso limits per approval tier.** Blocks the **Phase 1 exit gate**. The loop will build
routing against the deck's four bands and every approval test will be provisional until the real
numbers land. This is `B2` on the critical path; confirm by week 2.

**Item 14 — withholding tax rates.** The loop adds the columns in Phase 0 regardless, so nothing
stalls. But the rates change every amount that moves through the ledger, and correcting them
after the ledger has rows means restating postings.

**Item 9 and B3 — payroll rules.** Added when Phase 3 opened, and now the most dangerous
placeholder in the build. An approval band routed wrongly is embarrassing; a payroll rule applied
wrongly is somebody's pay, and they notice on the day it is released. Every rate is in
`config/payroll.php` so the SOP replaces them as an edit, and P3-13 is the re-test.

---

## Raised by the build, not by the deck

**PHP 8.3.30 is holding two packages back.** Laragon ships only 8.3.30, and it has now capped:

| Package | Installed | Current | Blocked by |
|---|---|---|---|
| `pestphp/pest` | 4.7.8 | 5.1.4 | Pest 5 requires PHP ^8.4 |
| `spatie/laravel-activitylog` | 4.12.3 | 5.1.1 | activitylog 5 requires PHP ^8.4 |

Neither is urgent — both installed versions are supported and do everything the plan needs.
But the pattern will repeat as more packages move to 8.4, and `PLAN.md` §9 lists "PHP version
EOL on the VPS" as a risk with the mitigation *"Laragon locally must match production"*. The
decision is therefore worth making once, deliberately:

- **Upgrade Laragon to PHP 8.4** and let both packages move to current, or
- **Stay on 8.3** and accept that new packages will increasingly resolve to older versions.

Whichever is chosen, production must match. Not blocking any phase today.
