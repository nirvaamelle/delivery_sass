# Runbook — backup, restore, and the audit trail

> Phase 6. The operational procedures somebody follows when it matters, written
> down because the morning you need a restore is not the morning to work out how
> one is done.
>
> `DEPLOY.md` covers getting code onto a server. This covers keeping the data on
> it, and proving that you can.

---

## 1. The rule this file exists for

**A backup nobody has restored is not a backup.** It is a file whose contents
nobody has checked, and the first time anybody checks is the morning they need
it. `PHASE-PLAN.md` makes this Phase 6's exit gate clause 2, and it is worded as
a thing that must have *happened*, not a thing that must exist.

So the deliverable is the rehearsal, and it is a command:

```sh
php artisan backup:rehearse --scratch=construction_scratch
```

It takes a real dump, restores it onto a scratch database, and prints what came
back — tables, projects, and the ledger's two triggers. It exits non-zero if the
restore proves nothing.

**Run it monthly.** A restore verified in March is not a restore verified.

---

## 2. What the rehearsal checks, and why each one

| Check | Why it is checked |
|---|---|
| Dump size | A zero-byte dump is the classic silent failure: the cron ran, the file exists, the disk was full. |
| Tables restored | A dump that stopped halfway through still ends in a valid file. |
| Projects restored | Schema without data is a restore that returns an empty company. |
| **Ledger triggers restored** | The ledger is append-only *because of two MySQL triggers*. A restore that brought every row back and left them behind returns a table anybody can edit — and nothing about it would look wrong. |

That last row is the one worth understanding. `mysqldump` does not include
triggers, routines or events unless asked, and the flags are easy to lose when
somebody rewrites the backup script. If the trigger count comes back as anything
but 2, the rehearsal fails loudly rather than reporting a successful restore.

---

## 3. Guards on the restore

Restore is the one operation in this system capable of destroying a database, so
it refuses before it writes:

1. **Never the connected database.** Checked first, because it is the most
   dangerous target and deserves the most specific message.
2. **Never a database without `scratch` in its name.** A marker rather than a
   blocklist — a blocklist is only as good as the last person who remembered to
   add to it, and the databases worth protecting are the ones nobody thought of.
3. **Never a missing or empty dump.** Restoring nothing would drop the target
   and put nothing back.

Restoring onto *production* is therefore deliberately not something this command
can do. That is a manual operation, done by a person who has read section 5.

---

## 4. Taking a backup on the server

```sh
# Nightly, from cron. Keep 30 daily and 12 monthly.
php artisan backup:rehearse --scratch=construction_scratch --keep
```

`--keep` leaves the dump on disk so the nightly file is both taken and proven in
one pass. Without a server this is untested in anger — **Part D item 12** — and
section 9 says what remains.

Off-box copies matter more than the schedule. A backup on the same disk as the
database survives a bad migration and nothing else.

---

## 5. Restoring for real

Not automated, on purpose.

1. Stop the queue workers. A payroll job resuming against half-restored data is
   worse than the outage.
2. Put the app in maintenance mode.
3. Restore the dump into a **scratch** database first and look at it. Check the
   last billing, the last payroll run, the ledger's row count.
4. Only then promote it, by pointing the application at the restored database or
   by renaming — never by restoring over the live one.
5. Run `php artisan migrate --force`. A dump is from the schema of its own day.
6. Restart the workers **after** the migration, not before. Without that the
   first payroll run after a restore executes the previous release, and the
   symptom is wrong numbers rather than an error.

---

## 6. The audit trail

```sh
php artisan activity:export --from=2026-01-01 --to=2026-03-31
```

Writes a CSV to the **private** disk. Both dates are required and inclusive —
"everything" is the easiest request to make by accident and the hardest to
produce.

Values that a spreadsheet would evaluate as a formula are prefixed with a tab
rather than stripped: the auditor must still see what was recorded, and deleting
characters from an audit trail to make it safe to read is worse than the
injection it prevents.

The export is deliberately **not** scoped to the runner's projects, unlike every
live screen. A partial audit trail that looks complete is worse than none.

---

## 7. Failed jobs, logs and the schedule — P6-06

**A failed job nobody hears about is a payroll nobody ran.** Laravel records a
failure in `failed_jobs` and tells nobody; a failed `ComputePayrollRun` shows up
as a register that never appeared, noticed on payday.

So every queue failure raises an alert in `job_failure_alerts`, addressed to the
role that can act (payroll → `finance-manager`; anything unmapped → `admin`, never
nobody). Repeats of the same job fold into the open alert with a count, so a
worker retrying every minute produces one alert, not sixty. It stays open until a
named person acknowledges it and says what was done.

```sh
php artisan ops:job-failures   # exits 1 while anything is unacknowledged
```

Point an uptime monitor at that exit code. Until Horizon runs on a server, it is
the only failed-job visibility that does not depend on somebody remembering to
look.

**Logs rotate daily and keep 30 days.** `single` grows one file until the disk is
full, and a full disk stops MySQL before it stops anything else. If a host still
has `LOG_STACK=single` in its `.env`, it overrides the default — check it.

**The schedule** (`php artisan schedule:list`):

| When | Command | Why |
|---|---|---|
| Daily | `queue:prune-failed --hours=336` | Old `failed_jobs` rows are noise; the *alert* outlives them. |
| Hourly | `ops:job-failures` | Hear about a failed payroll within the hour, not on payday. |
| 1st of month, 02:00 | `backup:rehearse` | A restore verified in March is not a restore verified. |

Deliberately absent: anything that prunes `activity_log`. It is append-only, and
a tidy-up job would quietly shorten the audit trail B4 reads. A test asserts it
stays absent.

The scheduler needs one cron line on the server:

```cron
* * * * * cd /var/www/construction && php artisan schedule:run >> /dev/null 2>&1
```

**Horizon is not installed, and that is recorded rather than hidden.** It
requires `ext-pcntl`, `ext-posix` and Redis. None exist on the Windows machine
this build runs on, so it cannot be installed or tested here — `composer require
laravel/horizon` fails the platform check. On the server:

```sh
composer require laravel/horizon
php artisan horizon:install
# QUEUE_CONNECTION=redis in .env, then supervise `php artisan horizon`
```

The alerting above keeps working under Horizon unchanged: it listens for Laravel's
`JobFailed` event, which Horizon workers raise too.

---

## 8. Server hardening — P6-07

The configuration is committed in `ops/` and each setting that fails *silently*
is pinned by `ServerHardeningTest`. None of it is applied yet, because there is
no server. Install each file, then run the check listed beside it **before**
reloading the service — a bad config followed by a reload is how a server locks
out the only person who could fix it.

| File | Installs to | Check before reload |
|---|---|---|
| `ops/ssh/sshd_config.d/00-construction.conf` | `/etc/ssh/sshd_config.d/` | `sshd -t` |
| `ops/fail2ban/jail.d/construction.local` | `/etc/fail2ban/jail.d/` | `fail2ban-client -t` |
| `ops/php-fpm/construction.conf` | `/etc/php/8.3/fpm/pool.d/` | `php-fpm8.3 -t` |
| `ops/php/99-construction.ini` | `/etc/php/8.3/fpm/conf.d/` **and** `cli/conf.d/` | `php -i \| grep expose_php` |
| `ops/mysql/construction.cnf` | `/etc/mysql/mysql.conf.d/` | see below |

### The three things in here that would have gone wrong quietly

**1. The ledger's triggers would not have been created.** MySQL 8 turns binary
logging on by default, and with it on, `CREATE TRIGGER` needs `SUPER`. The
ledger is append-only *because of* two triggers created by a migration, and the
staging template gives the application a least-privilege database user. This
was reproduced, not reasoned about: on the local 8.4.3 server, a user holding the
`TRIGGER` grant got

```
ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is
enabled (you *might* want to use the less safe log_bin_trust_function_creators variable)
```

So without `log_bin_trust_function_creators = 1`, the first `migrate --force` on a
real server fails on exactly the migration that makes the ledger immutable. The
test suite never caught it because it connects as root, which holds `SUPER`.
Granting the application user `SUPER` instead would be far less safe.

**2. The X Protocol port would have listened to the internet.** `bind-address`
covers port 3306 only. On 8.4, `mysqlx_bind_address` defaults to `*`, so port
33060 listens on every interface unless it is set separately. Also verified on
the local server.

**3. Password login over SSH would have stayed on.** sshd uses the *first* value
it reads for each keyword, and drop-ins load alphabetically. A provider's
`50-cloud-init.conf` setting `PasswordAuthentication yes` would beat a file named
`99-anything`. Hence `00-construction.conf`.

After the MySQL restart, confirm all of it took:

```sh
mysql -e "SELECT @@bind_address, @@mysqlx_bind_address, @@log_bin_trust_function_creators, @@sql_mode"
```

### SSH: do not lock yourself out

Apply the SSH file from one session and test a key-based login from a **second**
session before closing the first. `AllowGroups sshusers` is left commented in the
file: enable it only after that group exists and your own user is in it.

### Deliberately not done

No fail2ban jail on the application login. Filament's sign-in is a Livewire
component, so a failed attempt is a `POST` to `/livewire/update` — the same URL as
every other click on every screen. A log-based jail cannot tell a wrong password
from somebody paging a table, and would ban people for working. The login is
rate-limited inside the application; SSH is where an attacker gets the box.

---

## 9. Still needed — Part D item 12

Everything above is written and tested locally. What is not done, and cannot be
from here:

- The nightly cron actually installed on a server.
- Off-box retention: dumps copied somewhere the database's own disk failing does
  not take with it.
- A restore rehearsed **on staging** against staging's real data volume.
- The scheduler's cron line installed, and Horizon on Redis supervising the
  workers (section 7), and the hardening in P6-07.

Exit gate clause 2 says "a backup has been restored onto a scratch database and
the restore verified". That is **green locally** and green in CI. It is not yet
green on a server, because there is no server.
