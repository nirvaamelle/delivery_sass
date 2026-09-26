# Construction ERP

Construction operations ERP enforcing the gated process flows in
`concept/Construction-Operations-MBI.pptx`.

> Every process ends in the same place: the project cost ledger.
> Every document carries the project code, the cost code, and the reference
> number of the document before it.

## Documents

| File | What it is |
|---|---|
| `PLAN.md` | Scope, stack, data model, hard controls |
| `PHASE-PLAN.md` | Slide-by-slide deck review, findings F1–F18, phase plan |
| `BUILD-LOOP.md` | The build loop prompt |
| `BUILD-STATE.md` | Where the build is now — the loop's memory |
| `DECISIONS-PENDING.md` | Placeholders awaiting a client decision |

## Local setup

Laragon's binaries are not on PATH for non-interactive shells:

```sh
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
```

```sh
composer install
npm install && npm run build
php artisan migrate:fresh --seed
php artisan serve
```

Panel at `/admin` — `admin@construction.test` / `password` (local only).

## Stack

Laravel 13 · Filament 4.13 · MySQL 8.4 · PHP 8.3
