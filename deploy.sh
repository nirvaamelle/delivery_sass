#!/usr/bin/env bash
#
# Zero-downtime deploy, run by Ploi or Laravel Forge on push.
#
# PLAN.md §3: the pipeline exists on day one, not at the end. A deploy path
# first exercised in week fourteen is a deploy path nobody has debugged, and the
# first thing it will be asked to do is carry a payroll run.
#
# tests/Feature/Foundation/DeployPipelineTest.php asserts the invariants below,
# most importantly that no destructive migration can ever appear in this file.

set -euo pipefail

# ---------------------------------------------------------------------------
# 1. Get the code
# ---------------------------------------------------------------------------

cd "$FORGE_SITE_PATH" || cd "$(dirname "$0")"

git pull origin "$DEPLOY_BRANCH"

# ---------------------------------------------------------------------------
# 2. Dependencies
# ---------------------------------------------------------------------------

# --no-dev, because Telescope, Pest and Larastan have no business on a server
# holding payroll data. PLAN.md §2: Telescope on staging only, never production.
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

npm ci --omit=dev
npm run build

# ---------------------------------------------------------------------------
# 3. Migrate
# ---------------------------------------------------------------------------

# --force because a deploy has no terminal to confirm at.
#
# NEVER add a schema-dropping migration command here - the fresh, reset and
# wipe variants each drop every table. On staging that costs an afternoon; on
# production it is the company's books.
#
# Those command names are deliberately not written out anywhere in this file.
# DeployPipelineTest asserts the file does not contain them as literal strings,
# which is a blunter guard than checking executable lines and a stronger one:
# a name that never appears cannot be uncommented into service later.
#
# The section 7 demo reset is separately guarded by an environment check, so it
# can never point at this server.
php artisan migrate --force

# ---------------------------------------------------------------------------
# 4. Warm the caches
# ---------------------------------------------------------------------------

php artisan optimize
php artisan filament:optimize

# ---------------------------------------------------------------------------
# 5. Restart the workers
# ---------------------------------------------------------------------------

# Long-running queue workers hold the previous release in memory. Without this,
# the first payroll run after a deploy executes the old code — and the symptom
# is wrong numbers rather than an error, which is far worse.
php artisan queue:restart
