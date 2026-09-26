# Construction ERP — Build Plan

> Source of truth for scope: `concept/Construction-Operations-MBI.pptx`
> (12 slides — four process chains mapped across four project phases).
> Status: **building** — see `BUILD-STATE.md` for where the build is now.
> **Revised 2026-09-10:** stack settled on **PHP / Laravel 13 / Filament 4 / MySQL**, hosted on
> a **VPS managed by Ploi or Forge** (~$17/mo all-in), Singapore region. Sections 1, 4, 5 and 8
> are unchanged from the first draft — the domain model was always stack-agnostic.

---

## 1. What we are building

A construction operations ERP that enforces the gated process flows in the concept deck.
The organising principle from the deck, and of this system:

> **Every process ends in the same place: the project cost ledger.**
> **Every document carries the project code, the cost code, and the reference number of the document before it.**

That single rule — the *document handoff spine* — is the backbone of the data model. It is
what turns four separate workflows into one P&L. The three failure modes the deck names
(PRs raised without cost codes, deliveries received without a PO reference, site expenses
booked after cutoff) are prevented at the database level, not by policy.

### The four chains

| # | Chain | Runs in phase | Ends in ledger as |
|---|-------|---------------|-------------------|
| 1 | Project Acquisition → Cash | 1 → 4 | Revenue and receivables |
| 2 | Material & Vendor Acquisition | 1 → 4 | Material and subcontract cost |
| 3 | HRIS: Payroll & Timelogs | 1 → 4 | Labor cost |
| 4 | OPEX Consolidation | 1 → 4 | Overhead |

**All four chains run all four phases.** An earlier draft of this table scoped chains 2 and 3 to
phases 2–3 and chain 4 to phases 3–4. Slide 2's grid contradicts that — every chain has a
phase-1 cell and a phase-4 cell:

| Chain | Its phase-1 work | Its phase-4 work |
|---|---|---|
| 2 · Material & Vendor | Vendor and subcon accreditation, RFQ to three, bid tabulation and award | Final reconciliation, warranty turnover, vendor scorecard |
| 3 · HRIS | Manpower plan and rates approved | Demobilization, final pay and clearance |
| 4 · OPEX | OPEX budget allocated per project | Final OPEX close, project P&L |

This is not cosmetic. Vendor accreditation, the manpower plan and the OPEX budget allocation all
have to exist before the first project reaches Pre-Construction, which moves them earlier in the
build than §6 originally had them. Tracked as **F1** in `PHASE-PLAN.md`.

### The four phases

`Project Acquisition` → `Pre-Construction` → `Construction` → `Post-Construction`

---

## 2. Stack

### Why PHP, on the merits

An ERP is registers, forms, approvals and printed documents — that shape, repeated forty times.
**Filament is the most complete tool in any language for exactly that shape.** The alternatives
were weighed honestly:

| Alternative | Verdict |
|---|---|
| **Laravel + Filament** | ✅ Ships the ~40% that is identical across every module |
| Django + Django admin (Python) | Admin is far weaker than Filament — adequate for data entry, poor for approval workflows |
| Rails + Avo | Genuinely capable, but Rails hiring in this market is thin |
| Next.js + TypeScript | Every table and form is hand-built. Only wins if the team is already TS-native |

Three further reasons it fits *this* team: Laragon already runs PHP/MySQL, so local and
production are identical; PHP runs on the cheapest hosting that exists, while Node needs a
persistent process; and Laravel developers are abundant and affordable here.

**When the answer would flip:** heavy real-time (live equipment telemetry) favours Node or
Elixir; ML-driven forecasting favours Python. Neither applies — forecast-to-completion is
arithmetic. "PHP is too slow" is not a real objection: PHP 8.3 with OPcache handles a
low-concurrency, high-complexity workload like this comfortably.

**Buy versus build, for the record:** **ERPNext** (free, open source) already covers
PR → RFQ → PO → receipt → three-way match, plus HR and Projects — perhaps 60% of this, at zero
licence cost. The recommendation is still to build, because the remaining 40% is precisely
where the deck's value sits: the joint-accomplishment-survey gate, 30/50/75/95/100 retention
billing, PH statutory payroll, the cutoff calendar. Bending ERPNext to those means learning
Frappe, and fighting an unfamiliar framework is slower than building in a familiar one. Worth
knowing the option exists before committing fourteen weeks.

### The stack

| Layer | Pick | Why this one | Billing |
|-------|------|--------------|---------|
| **Runtime** | **PHP 8.3+ / Laravel 13** | Matches Laragon exactly. Laravel brings the parts an ERP needs out of the box: DB transactions, queues, a scheduler, policies, migrations, events. | Free (MIT) |
| **Admin & UI** | **Filament 4** (Livewire 3 + Alpine + Tailwind) | This is the reason the timeline compresses. Filament gives you resource CRUD, sortable/filterable tables, form builder, bulk actions, approval-style Actions with confirmation forms, widgets and a notification bell — all server-rendered, no separate frontend build. An ERP is registers and forms; Filament is register-and-form shaped. | Free (MIT) |
| **Database** | **MySQL 8 / MariaDB** | Native on every Hostinger plan and on Laragon. InnoDB transactions, `DECIMAL(18,4)` for money, `SELECT … FOR UPDATE` for gapless document numbering. Everything the controls in §5 need. | Included with hosting |
| **Auth** | **Laravel auth + Filament panel login** | Self-hosted, sessions in your own DB, **no per-seat cost** — every foreman and timekeeper is a user. | Free |
| **Roles & permissions** | **spatie/laravel-permission** | Roles, permissions and per-project policies. Maps directly onto the approval matrix. | Free (MIT) |
| **Audit trail** | **spatie/laravel-activitylog** | Who changed what, when — non-negotiable for an ERP that produces a P&L. | Free (MIT) |
| **Attachments** | **spatie/laravel-medialibrary** | Receipts, DRs, test certificates, 201 files, as-builts. Stores on the server disk; move to S3-compatible later without code changes. | Free (MIT) |
| **Printed documents** | **barryvdh/laravel-dompdf**, or **spatie/browsershot** on VPS | PO, billing form, payslip, abstract of canvass. DomPDF runs anywhere; Browsershot needs headless Chrome (VPS only) but renders far better. | Free (MIT) |
| **Import / export** | **maatwebsite/excel** | Biometric timelog CSV import, register and BvA exports. Your team will want Excel out of every screen — plan for it rather than fighting it. | Free (MIT) |
| **Background jobs** | **Laravel queues** — Redis + Supervisor on VPS, `database` driver on shared | Payroll runs, month-end consolidation, PDF batches, AR aging sweeps. These must not run inside a web request. | Free |
| **Queue dashboard** | **Laravel Horizon** (VPS + Redis) | Visibility into failed payroll jobs. Worth having before the first real cutoff. | Free (MIT) |
| **Scheduling** | **Laravel scheduler** — one cron entry | Drives the OPEX cutoff calendar, AR aging, accreditation expiry, scorecard recalculation. | Free |
| **Backups** | **spatie/laravel-backup** → Backblaze B2 or Cloudflare R2 | **Off-server**, nightly, DB + media. A backup on the same VPS is not a backup. | B2 ~**$0.006/GB-mo**; R2 $0.015/GB-mo, zero egress |
| **Charts** | **Filament widgets** (Chart.js) | S-curve, budget vs actual, cost per unit of accomplishment. | Free |
| **Email** | **Hostinger mailbox SMTP**, or **Resend** | Approval notifications, RFQs to vendors, billing submissions. Resend if deliverability matters. | Hostinger: included. Resend: free 3k/mo → $20/mo |
| **Error tracking** | **Sentry** free tier | Telescope on staging only — never on production. | Free 5k/mo → $26/mo |
| **Server** | **VPS + Ploi/Forge**, Singapore region | See *Where to host* below. | ~$17/mo all-in |
| **2FA** | **Filament native 2FA** | Mandatory for Finance, HR and Admin roles. | Free |

### Where to host

**The hosting provider matters less than whether a management layer hardens the server for
you.** A hand-rolled $5 VPS running payroll data is cheap and insecure. The same $5 VPS
provisioned by Ploi or Laravel Forge is cheap and correct. Budget for the management layer
before optimising the server line.

| Piece | Pick | ~$/mo | Why |
|---|---|---|---|
| Server | **Hetzner CX22** or **DigitalOcean**, **Singapore region** | 5–7 | Flat pricing, no renewal step-up. Singapore is ~30–50 ms from Manila |
| Management | **Ploi** or **Laravel Forge** | 10–12 | The security purchase: Nginx, PHP-FPM, auto-renewing TLS, firewall, Supervisor-managed queue workers, cron, zero-downtime deploys — correct by default |
| Backups | **Backblaze B2**, encrypted, off-server | ~1 | A snapshot on the same VPS is not a backup |
| Domain | | ~1 | |
| **Total** | | **~$17** | |

**Shared hosting is disqualified for production.** No Supervisor means a queue worker can only
be approximated by a per-minute cron, and the two heaviest jobs in this system — a payroll run
across a full site's headcount, and the month-end OPEX consolidation — will exceed the
execution limit and die halfway, leaving a **half-posted ledger**. That is the one failure mode
an ERP cannot have. Shared is acceptable for the §7 demo only.

**On Hostinger specifically:** its VPS is fine and a couple of dollars cheaper, but the headline
price is a 48-month intro rate that typically renews at two to three times the sticker, and you
would still want Ploi on top. Hetzner is cheaper at renewal on better hardware. Compare
**renewal** prices, not promo prices, whichever you pick.

**Skip fully-managed PHP hosts** (Cloudways, Laravel Cloud) — more cost for less control, and
Ploi already removes the sysadmin burden.

### Cost summary

- **Demo:** ~$0 marginal on any existing plan.
- **Production:** **~$17/mo** all-in — server, management, off-server backups, domain.
  TLS is free (Let's Encrypt).
- **Previous Vercel plan, for comparison:** ~$60–80/mo.

No licence cost anywhere — Laravel, Filament and every Spatie package above are MIT.

### What you gain over the Vercel/Next.js plan

- **One stack, three environments.** Laragon local, staging subdomain, production VPS — same
  PHP, same MySQL, same everything. No serverless/Postgres impedance mismatch to debug.
- **Filament removes a large slice of the build.** Tables, forms, filters, bulk actions,
  approval dialogs and the notification inbox are configuration, not code.
- **Roughly a third of the running cost**, with no per-seat auth fee.
- **Your data sits in one MySQL you control** — which is what you want for something that
  produces an audited P&L.
- **PHP hiring** is easier and cheaper in this market than Next.js/TypeScript hiring.

### What you give up — and the mitigation

| Give up | Mitigation |
|---|---|
| Preview URL per branch | A `staging.` subdomain with its own database, deployed from the `staging` branch |
| Managed platform — patching, uptime, scaling are now yours | Ploi/Forge covers most of it by default; plus nightly off-server backups, `unattended-upgrades`, fail2ban and an uptime monitor. Budget roughly half a day a month for ops |
| Autoscaling | Irrelevant at this size — an ERP for one construction company is a low-concurrency, high-complexity workload. A KVM 2 handles it with room to spare |
| Managed Postgres (branching, PITR) | MySQL binlog + nightly dumps. Test the restore, don't assume it |

---

## 3. Architecture

```
Hostinger VPS · Ubuntu LTS
│
├─ Nginx ──▶ PHP-FPM 8.3 ──▶ Laravel 13
│                               │
│                               ├─ Filament panel  /app
│                               │   ├─ Projects      cockpit, cost ledger, P&L
│                               │   ├─ Acquisition   contracts, billings, invoices, ORs, AR aging
│                               │   ├─ Procurement   vendors, PR, RFQ, canvass, PO, subcontracts
│                               │   ├─ Warehouse     DR, receiving, inspection, stock, issuance
│                               │   ├─ HRIS          employees, manpower, DTR, payroll, payslips
│                               │   ├─ OPEX          capture, liquidation, coding, close, BvA
│                               │   ├─ Approvals     unified inbox — every gate lands here
│                               │   └─ Admin         roles, approval matrix, cost codes, COA, calendar
│                               │
│                               └─ app/Domain/… ── Eloquent ──▶ MySQL 8
│                                    ├─ Numbering    gapless per-type, per-year sequences
│                                    ├─ Gates        preconditions per state transition
│                                    ├─ Approvals    authority matrix, four tiers
│                                    └─ Posting      sole writer to project_cost_ledger
│
├─ Redis ──▶ Supervisor ──▶ queue workers   payroll runs, month-end close, PDF batches
├─ Cron  ──▶ php artisan schedule:run       cutoff calendar, AR aging, accreditation expiry
└─ spatie/laravel-backup ──▶ Backblaze B2 / R2, nightly, DB + media
```

### The one architectural rule

**Domain logic lives in `app/Domain`, never in a Filament resource.** Filament is the UI layer
and nothing else. Gates, approvals, numbering and ledger posting are plain PHP services called
from Filament Actions, from queued jobs, and from tests alike. This keeps the rules testable
without booting a panel, and means a Filament major-version upgrade — or replacing it outright —
never touches the business rules.

### Four cross-cutting services, built once and first

1. **Numbering** — gapless, per-document-type, per-year sequences (`PR-2026-00142`). A locked
   counter row inside the same transaction as the insert. Never `MAX(id) + 1`.
2. **Gates** — declarative preconditions per document. *No PO without an approved PR and a
   tabulated bid. No mobilization without a countersigned PO. No billing without a verified
   statement of accomplishment.* Enforced in the service layer on every transition, plus a DB
   constraint wherever one can express it.
3. **Approvals** — reads the authority matrix (the four tiers on slide 5), routes to approvers,
   records who approved what and when, and handles the "approved one level above" escalation
   for sole-source purchases. Surfaces as one inbox, not seven.
4. **Posting** — the only code permitted to write `project_cost_ledger`. Every posting carries
   `project_code`, `cost_code`, `source_document_type`, `source_document_id`.

### Environments and deployment

`Laragon (local)` → `staging.<domain>` → `production`

Deploy through Ploi/Forge's Git deploy script — zero-downtime symlinked releases,
`php artisan migrate --force`, `optimize`, `queue:restart` — triggered by a push. Staging runs
the same pipeline against its own database and is where the demo in §7 lives.

### Security baseline

Ploi/Forge configures the first group correctly by default. The second group is yours and must
be done explicitly.

**Infrastructure**
- SSH keys only, password authentication disabled
- UFW open on 80/443/22 only — **MySQL and Redis bound to `127.0.0.1`, never public**
- fail2ban on SSH and the login route
- Unattended security upgrades
- Let's Encrypt with auto-renewal; TLS 1.2+, HSTS

**Application**
- **2FA mandatory** on the Filament panel for Finance, HR and Admin roles
- **Sensitive columns encrypted at rest** via Laravel's `encrypted` cast: vendor bank details,
  employee government numbers, salary rates. One line per column — do it in the first migration,
  not later
- Per-project row-level policies, so a site engineer sees only their own project
- Rate-limited login; session timeout; `APP_DEBUG=false` in production, always
- A dedicated least-privilege DB user per environment
- `composer audit` in CI; Telescope on staging only, never production

**Data**
- Encrypted, off-server nightly backups (DB + media) via `spatie/laravel-backup`
- **Restore-tested quarterly onto a scratch database.** An untested backup is not a backup
- `activity_log` treated as append-only — no UI path deletes or edits it

### Compliance — confirm before Phase 4

Assumed jurisdiction is the Philippines, inferred from the peso approval tiers and the
SSS / PhilHealth / Pag-IBIG / BIR references in the deck. Confirm before the ledger is built,
because both items below can impose requirements on numbering, audit trails and immutability
that are painful to retrofit.

1. **Data Privacy Act (RA 10173).** Payroll data is *sensitive personal information*.
   Processing it for 1,000+ individuals triggers NPC registration and a designated Data
   Protection Officer; breaches require notification within 72 hours. Hosting abroad is
   permitted — accountability stays with you regardless of where the server sits.
2. **BIR Computerized Accounting System (CAS) registration.** If this becomes the official
   books of account, CAS registration is likely required and electronic records must be
   retained for ten years. **Ask the company accountant before Phase 4 begins.**

---

## 4. Data model

Table names below are the migration names — snake_case plural, Eloquent conventions.

### Foundation
`organizations` · `users` · `roles` · `permissions` · `approval_matrix` · `activity_log`
`document_sequences` · `media` · `document_links` *(the handoff spine — predecessor and
successor edges between every document)*

### Project spine
`projects` (code, client, phase, status) · `contracts` (NOA, NTP, contract sum, retention %, DLP)
`cost_codes` (WBS tree) · `budgets` · `budget_lines` · `cutoff_calendars`

### Chain 1 — Acquisition to cash
`billing_schedules` · `billing_milestones` (30/50/75/95/100 default, per-contract override)
`accomplishments` (joint survey, client-rep signature, photos, S-curve point)
`billings` (submitted → approved | returned-with-reason) · `billing_deductions`
`sales_invoices` · `official_receipts` · `ar_ledger` · `retention_ledger` · `retention_releases`

### Chain 2 — Procurement
`vendors` · `vendor_accreditations` (12-month expiry) · `vendor_documents` · `vendor_scorecards`
`boms` · `bom_lines` · `purchase_requisitions` + lines
`rfqs` · `rfq_recipients` · `quotes` + lines · `bid_tabulations` · `sole_source_justifications`
`purchase_orders` + lines · `subcontracts`
`delivery_receipts` · `receiving_reports` · `inspections` · `return_to_vendor`
`stock_cards` · `material_issuances` · `physical_counts`
`three_way_matches` · `ap_vouchers` · `payments`

### Chain 3 — HRIS
`manpower_plans` · `manpower_requisitions` · `employees` (201) · `employment_contracts`
`biometric_enrollments` · `time_logs` · `dtr_periods` · `dtr_lines` · `overtime_approvals`
`payroll_periods` (1–15, 16–EOM) · `payroll_runs` · `payroll_lines`
`loans` · `cash_advances` · `statutory_deductions` · `payslips` · `disbursements`
`clearances` · `final_pays`

### Chain 4 — OPEX
`chart_of_accounts` · `cost_centers` · `petty_cash_funds` · `expense_entries`
`liquidations` · `opex_budgets` · `monthly_closes` · `budget_vs_actual` · `variance_explanations`

### The ledger
`project_cost_ledger` (every posting, immutable) · `journal_entries` + `journal_lines`
`project_pnl` · `cost_per_unit_accomplishment` · `forecast_to_completion`

**Money is `DECIMAL(18,4)` everywhere.** Never float, never integer cents.

---

## 5. Hard controls to encode

These come straight from the deck and are the reason the system exists. Each is a database
constraint or a service-layer gate — never a UI-only check, because Filament forms can be
bypassed by a queued job or an import.

| Control | Where enforced |
|---|---|
| No PR without a cost code and confirmed budget availability | PR insert — FK + budget check |
| Three quotes minimum before award; sole source needs written justification approved one level up | Gate on bid tabulation |
| No PO without an approved PR **and** a tabulated bid | Gate on PO creation |
| PO before delivery — no receiving report without a PO reference | Non-nullable FK |
| No payment without a three-way match (PO ↔ receiving report ↔ invoice) | Gate on AP voucher |
| No mobilization without a countersigned PO | Gate on mobilization checklist |
| No billing without a client-signed joint accomplishment survey | Gate on billing submission |
| 10% retention withheld on every billing, released after the DLP | Automatic on billing post |
| Returned billings log the deduction and its reason so the item is not billed twice | Unique index on `billing_deductions` |
| No timelog, no pay — unvalidated days held to the next cutoff | Payroll run excludes unvalidated DTR lines |
| OT and night differential need written approval before payroll picks them up | Gate on payroll line |
| An expense with no receipt, no cost code, or no budget line is **not booked** — returned the same day | Expense entry validation |
| Nothing books after the cutoff date | Cutoff calendar check on every posting |
| Variance above 10% requires a written explanation | Blocks month-end close |
| Accreditation expires at 12 months; expired vendors cannot receive an RFQ | Gate on RFQ recipient |
| Two late deliveries in a quarter, or rejection rate above 5%, suspends a vendor | Scheduled scorecard job → vendor status |
| Falsified documents — immediate removal | Manual status change, activity-logged |
| A project stays open in the books until retention is collected | Close-out gate |

### Cycle-time targets (slide 11) — tracked, not just documented

Every document records `triggered_at` and `signed_output_at`. A Filament dashboard widget
reports misses against: acquisition 5 days · PR 1 day · vendor award 3–5 days ·
delivery-to-payment 30 days · progress billing 5 days after cutoff · payroll 3 days after
cutoff · OPEX close by day 30, report by day 5. Each miss surfaces with its reason and the
affected project — which is exactly what the weekly operations meeting needs.

---

## 6. Delivery roadmap

Shorter than the Next.js version by roughly two weeks, almost entirely because Filament ships
the CRUD, tables and approval dialogs that would otherwise be hand-built.

**Phase 0 — Foundation (week 1)**
Laravel 13 + Filament 4 skeleton; spatie permission / activitylog / medialibrary; organization,
project, cost-code and budget models; the numbering, gate, approval and posting services;
approval-matrix admin; the unified approvals inbox shell. Deploy pipeline and the staging
subdomain, working, on day one — not at the end.
*Nothing user-visible ships without this. It is the substrate for all four chains.*

**Phase 1 — Procurement chain (week 2–4)** — highest daily volume, so it proves the model
PR → RFQ → canvass → tabulation → tiered approval → PO → DR → receiving → inspection →
issuance → three-way match → AP voucher. Vendor accreditation and scorecards.
First ledger postings.

**Phase 2 — Acquisition to cash (week 5–6)**
Contract, billing schedule, accomplishment / joint survey, billing with the approved-or-returned
branch, sales invoice, official receipt, AR aging, retention ledger.

**Phase 3 — HRIS and payroll (week 7–9)**
Employee 201, manpower requisition, timelog import (CSV from biometrics first; device API
later), DTR validation, OT approval, queued payroll run, register review, payslip PDF,
disbursement file, labor cost posting.

**Phase 4 — OPEX and the ledger (week 10–11)**
Expense capture, liquidation, coding, and the day 1–25 / 26 / 27 / 28 / 29 / 30 / 3–5 calendar
built as an actual state machine driven by the scheduler, not as a reminder. Budget vs actual,
variance explanations, month-end close. Ledger consolidation and project P&L.

**Phase 5 — Close-out and reporting (week 12–13)**
Substantial completion, punchlist with subcontractor back-charges, turnover pack, final
billing, demobilization and clearance, retention release, final P&L, forecast to completion,
vendor and subcon scorecards filed.

**Phase 6 — Hardening and ops (week 14)**
Per-project row-level policies; **a backup restored onto a scratch database to prove it works**;
Horizon and failed-job alerting; log rotation; PHP-FPM and MySQL tuning; fail2ban and SSH
hardening; indexes checked against five years of simulated document volume; activity-log export.

---

## 7. Demo on the staging subdomain

One seeded demo company, one project, walked end to end so a reviewer sees the spine work —
and, more usefully, sees the gates *reject* things:

1. Project awarded — contract signed, project code and budget opened.
2. Site raises a PR against a cost code; the budget check blocks an over-budget line.
3. RFQ to three accredited vendors → canvass → tabulation → tier-based approval → PO.
4. Delivery → receiving report with a short delivery noted → inspection with one RTV → issuance.
5. Three-way match → AP voucher. **Material cost appears in the ledger.**
6. One semi-monthly payroll cutoff: DTR validation holding an unvalidated day, OT approval,
   queued payroll run, payslip PDF. **Labor cost appears in the ledger.**
7. One OPEX month close: an expense rejected for a missing cost code, and a 12% variance
   blocking the close until explained. **Overhead appears in the ledger.**
8. 30% downpayment billing plus one 50% progress billing, and one *returned* billing showing
   the re-measurement loop. **Revenue and receivables appear in the ledger.**
9. Project P&L and cost per unit of accomplishment, assembled from all four chains.

Reset with `php artisan migrate:fresh --seed` against the staging database only — guarded by an
environment check so it can never point at production.

---

## 8. Decisions needed before build (slide 12)

These are yours to confirm. The system needs each one as configuration, and every unanswered
item becomes a hard-coded assumption:

1. **Peso limits for each of the four approval tiers.** The deck deliberately shows bands, not
   amounts.
2. **Billing milestones per contract type.** 30/50/75/95/100 is the deck default — fixed, or
   per contract?
3. **Retention rate and defects liability period.** 10% assumed; the DLP length is unstated.
4. **Payroll cutoff and release dates.** 1–15 / 16–EOM, DTR closing one day after cutoff and
   release three working days later, is assumed.
5. **Named owner for each of the seven acquisition steps.** Roles are in the deck; people are not.
6. **Which forms already exist in a system and which are still manual** — this decides what we
   import versus what we build data entry for.
7. **Existing systems to integrate or replace.** The deck names an accounting system, an HRIS
   payroll module, biometrics and an inventory module — are these real products with APIs, or
   spreadsheets?
8. **Biometric device make and model** — decides whether timelogs arrive by CSV import or live API.
9. **Statutory deductions** — jurisdiction (SSS / PhilHealth / Pag-IBIG / BIR assumed from the
   peso reference) and whose computation tables we follow.
10. **Multi-company or single company?** Decides whether `organizations` is a tenant boundary
    or just a label — and it is expensive to change later.

**Added by the hosting review:**

11. **Confirm the jurisdiction** is the Philippines, and get the accountant's answer on **BIR
    CAS registration** (§3, Compliance). This gates Phase 4.
12. **Approve the ~$17/mo production hosting line** — VPS plus Ploi/Forge. The management layer
    is the security purchase, not an optional extra.
13. **Headcount to be processed in payroll.** Above 1,000 individuals, NPC registration and a
    designated Data Protection Officer become obligations, not options.

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| Scope — this is four ERP modules, not one | Ship the procurement chain first and run one real cycle on it before starting chain 2 |
| **Single server is a single point of failure** | Nightly off-server backups to B2/R2, an uptime monitor, and a documented rebuild procedure. A VPS snapshot is not a backup either |
| **A backup that has never been restored is not a backup** | Phase 6 restores one onto a scratch DB. Repeat quarterly |
| Shared hosting kills a long payroll run halfway | Disqualified for production; run payroll on Supervisor-managed workers inside a DB transaction so a failure rolls back cleanly rather than half-posting |
| Promo hosting pricing renewing at 2–3× | Compare renewal prices, not sticker prices, before committing to a term |
| **Payroll and vendor bank data on a self-managed server** | Ploi/Forge hardening, 2FA on privileged roles, encrypted columns, encrypted off-server backups — see §3, Security baseline |
| BIR CAS requirements discovered after the ledger is built | Confirm with the accountant before Phase 4, not after |
| Filament major-version upgrades touching business rules | Domain logic stays in `app/Domain`; Filament resources only call into it |
| Spreadsheet habits survive the rollout | Gates are server-side with no back door; the ledger only accepts posted documents |
| Approval fatigue at Tier 1 | Tier 1 stays a single-canvass, one-working-day path — do not add signatures to it |
| Biometric integration slips | CSV import ships first, so payroll is never blocked on a device vendor |
| PHP version EOL on the VPS | Pin to a supported PHP, schedule the upgrade; Laragon locally must match production |
