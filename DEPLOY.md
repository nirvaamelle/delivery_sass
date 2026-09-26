# Deploying

> Status: **the pipeline exists; the server does not.** Everything in §1 is
> built and tested. Everything in §2 needs a decision and a credit card, and is
> why P0-16 is `blocked` rather than `done` in `BUILD-STATE.md`.

---

## 1. What is already built

| Piece | File | Proven by |
|---|---|---|
| CI — the loop's whole check list, on a clean machine | `.github/workflows/ci.yml` | `DeployPipelineTest` asserts every check is still in it |
| Deploy script — pull, install, migrate, warm, restart workers | `deploy.sh` | `bash -n`, plus tests for `--force`, `--no-dev`, `queue:restart` |
| Staging environment template | `.env.staging.example` | tests for `APP_DEBUG=false`, own database, `APP_ENV=staging` |

**CI runs against MySQL 8.4, not SQLite.** `PLAN.md` §2 picks MySQL for
`SELECT … FOR UPDATE` on gapless numbering, and the ledger's append-only
guarantee is a pair of MySQL triggers. A suite green on SQLite would prove
neither of them.

**`deploy.sh` contains no schema-dropping command, and a test enforces that the
names never appear in the file at all** — a blunter guard than checking only
executable lines, and a stronger one, because a name that never appears cannot
be uncommented into service later.

CI has not run yet: `build/phase-0` has never been pushed. It will run on the
first push, since the workflow triggers on `build/**`.

---

## 2. What is still needed — Part D item 12

The remaining work is provisioning, and it costs money, so it is yours to
approve rather than mine to assume. From `PLAN.md` §2:

| Piece | Recommendation | ~$/mo |
|---|---|---|
| Server | Hetzner CX22 or DigitalOcean, **Singapore region** | 5–7 |
| Management | **Ploi** or **Laravel Forge** | 10–12 |
| Off-server backups | Backblaze B2, encrypted | ~1 |
| Domain | | ~1 |
| **Total** | | **~$17** |

**The management layer is the security purchase, not an optional extra.** A
hand-rolled $5 VPS running payroll data is cheap and insecure; the same $5 VPS
provisioned by Ploi is cheap and correct — Nginx, PHP-FPM, auto-renewing TLS,
firewall, Supervisor-managed queue workers, cron, zero-downtime deploys, all
correct by default.

**Shared hosting is disqualified for production.** No Supervisor means a queue
worker can only be approximated by a per-minute cron, and the two heaviest jobs
in this system — a payroll run across a full site's headcount, and the month-end
OPEX consolidation — will exceed the execution limit and die halfway, leaving a
**half-posted ledger**. That is the one failure mode an ERP cannot have.

### Once the server exists

1. Create the site in Ploi/Forge, pointed at `staging.<yourdomain>`, deploying
   from the `staging` branch.
2. Paste `deploy.sh` into the site's deploy script; enable Quick Deploy.
3. Copy `.env.staging.example` to the server's `.env` and fill:
   `APP_KEY` (`php artisan key:generate`), `DB_PASSWORD`, `APP_URL`, and
   `DEMO_ADMIN_PASSWORD`. The last one is **required**: the demo reset creates an
   administrator and every approval-matrix approver, and outside local and testing
   the seeders refuse to run without it — or with it set to `password` (P6-08).
4. Create the database and a **least-privilege user for this environment only**
   (`PLAN.md` §3). The staging user must not be able to reach production.
5. **Apply `ops/mysql/construction.cnf` and restart MySQL before the first push.**
   Not optional, and the order matters. MySQL 8 has binary logging on by default,
   and with it on, a least-privilege user is refused `CREATE TRIGGER` (ERROR 1419,
   reproduced locally in P6-07). The ledger's append-only guarantee is two
   triggers created by a migration, so without this file the first deploy's
   `migrate --force` fails on exactly that migration. Confirm with
   `mysql -e "SELECT @@log_bin_trust_function_creators"` → `1`. The rest of the
   hardening in `ops/` is in `RUNBOOK.md` section 8.
6. Enable Let's Encrypt, and a Supervisor-managed queue worker.
7. Push to `staging` and watch the deploy.
8. Seed the §7 demo: `php artisan migrate:fresh --seed --force` — **staging
   only**. The seeders refuse production outright (`DemoSeedGuard`, P6-08),
   checked before a single row is written; verified on the real command line to
   exit 1 before it even connects to the database.

### Then the Phase 0 exit gate can be claimed

> A PR can be raised, routed through a two-tier approval, and **rejected by the
> budget check** — end to end, on staging, deployed by push.

Every piece of that exists and is tested locally. What cannot be demonstrated
without a server is the "on staging, deployed by push" clause, which is the
whole reason the gate is worded that way: `PLAN.md` §6 puts the pipeline on day
one because a deploy path first exercised in week fourteen is a deploy path
nobody has debugged.

---

## 3. Before production, not after

- **Restore a backup onto a scratch database and prove it works.** *Built and
  verified locally (P6-04):* `php artisan backup:rehearse` dumps, restores onto a
  scratch database and counts the ledger's triggers; it is scheduled monthly.
  **Still to do on the server:** run it there once against real data volume, and
  set up off-box copies. See `RUNBOOK.md` sections 1–5.
- **2FA on the Filament panel** for Finance, HR and Admin roles. *Built (P6-05,
  P6-05a), and currently **switched off** at the client's request.* With
  `TWO_FACTOR_ENABLED=false` nobody is asked to set up an authenticator or enter a
  code. **To turn it on:** set `TWO_FACTOR_ENABLED=true` in `.env` — each Finance,
  HR and Admin user is then sent to set up an authenticator on their next login,
  and asked for a code at every sign-in after that. Then run
  `php artisan security:two-factor-status`; it exits non-zero while 2FA is off or
  while any of those accounts has not enrolled. Go-live gate clause 4 does not hold
  until it passes.
- **Apply the hardening configuration** in `ops/` (P6-07) — SSH, fail2ban,
  PHP-FPM, MySQL. Committed and test-pinned, not yet applied, because there is no
  server. `RUNBOOK.md` says where each file goes.
- **Confirm BIR CAS registration** with the company accountant (Part D item 11).
  It gates Phase 4 and can impose requirements on numbering, audit trails and
  immutability that are painful to retrofit.
