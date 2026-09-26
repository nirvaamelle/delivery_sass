<?php

/*
|--------------------------------------------------------------------------
| Server hardening configuration — P6-07
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Phase 6: "PHP-FPM and MySQL tuning. fail2ban and SSH hardening."
|
| There is no server (Part D item 12), so nothing here can be APPLIED yet. What
| can be done is to commit the configuration and pin the settings that matter,
| the way `DeployPipelineTest` pins `deploy.sh`: a hardening file that drifts is
| one nobody notices has drifted, because the server still boots.
|
| Each assertion is a setting whose absence fails SILENTLY — the server works,
| and is quietly less safe or quietly wrong. Settings whose absence fails loudly
| are left to the server to complain about.
|
*/

function opsFile(string $path): string
{
    $full = base_path('ops/'.$path);

    expect(file_exists($full))->toBeTrue("Missing ops/{$path}");

    return (string) file_get_contents($full);
}

/**
 * The value a config file sets for a key, ignoring commented-out lines.
 *
 * Comments are skipped on purpose: `# PasswordAuthentication no` in a file is a
 * note, not a setting, and a test matching the raw text would pass on it.
 */
function opsSetting(string $path, string $key): ?string
{
    foreach (preg_split('/\R/', opsFile($path)) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }

        if (preg_match('/^'.preg_quote($key, '/').'\s*(?:=\s*|\s+)(.+)$/i', $line, $match) === 1) {
            return trim($match[1]);
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| SSH
|--------------------------------------------------------------------------
*/

it('loads before any provider drop-in that could override it', function () {
    // sshd uses the FIRST value it reads for each keyword, and drop-ins load in
    // alphabetical order. Named 99-, a cloud-init 50- file setting
    // PasswordAuthentication yes would win — and every assertion below would
    // still pass while the server accepted passwords.
    expect(file_exists(base_path('ops/ssh/sshd_config.d/00-construction.conf')))->toBeTrue();
});

it('refuses root login over SSH', function () {
    expect(opsSetting('ssh/sshd_config.d/00-construction.conf', 'PermitRootLogin'))->toBe('no');
});

it('refuses password authentication over SSH', function () {
    // The whole reason fail2ban is a second line rather than the first: with
    // passwords off, there is nothing for a brute-force attempt to guess.
    expect(opsSetting('ssh/sshd_config.d/00-construction.conf', 'PasswordAuthentication'))->toBe('no')
        ->and(opsSetting('ssh/sshd_config.d/00-construction.conf', 'KbdInteractiveAuthentication'))->toBe('no');
});

it('ignores a commented-out setting rather than reading it as set', function () {
    // The parser guard, tested. Without it every assertion above would pass on
    // a file that only mentions the right values in a comment.
    $probe = base_path('ops/.probe-'.uniqid().'.conf');
    file_put_contents($probe, "# PermitRootLogin no\nPermitRootLogin yes\n");

    try {
        expect(opsSetting(basename($probe), 'PermitRootLogin'))->toBe('yes');
    } finally {
        @unlink($probe);
    }
});

/*
|--------------------------------------------------------------------------
| fail2ban
|--------------------------------------------------------------------------
*/

it('enables the SSH jail', function () {
    expect(opsFile('fail2ban/jail.d/construction.local'))->toContain('[sshd]')
        ->and(opsSetting('fail2ban/jail.d/construction.local', 'enabled'))->toBe('true');
});

it('bans for longer each time the same address comes back', function () {
    // A fixed ban teaches a scanner the ban length. An increasing one does not.
    expect(opsSetting('fail2ban/jail.d/construction.local', 'bantime.increment'))->toBe('true');
});

/*
|--------------------------------------------------------------------------
| PHP-FPM
|--------------------------------------------------------------------------
*/

it('never displays errors from PHP-FPM', function () {
    // A rendered error page contains the stack trace, and the stack trace
    // contains the database credentials — the same reason APP_DEBUG is false.
    expect(opsFile('php-fpm/construction.conf'))->toContain('php_admin_flag[display_errors] = off');
});

it('does not announce the PHP version', function () {
    // In the conf.d INI rather than the pool file: expose_php is a system-level
    // directive, and a pool's php_admin_* is not a reliable place for it. The
    // first draft of this test asserted it in the pool, which would have passed
    // while possibly doing nothing.
    expect(opsSetting('php/99-construction.ini', 'expose_php'))->toBe('Off');
});

it('recycles PHP-FPM workers so a slow leak cannot become an outage', function () {
    expect((int) opsSetting('php-fpm/construction.conf', 'pm.max_requests'))->toBeGreaterThan(0);
});

it('executes only .php files through PHP-FPM', function () {
    // An uploaded file named receipt.jpg containing PHP must not run.
    expect(opsSetting('php-fpm/construction.conf', 'security.limit_extensions'))->toBe('.php');
});

/*
|--------------------------------------------------------------------------
| MySQL
|--------------------------------------------------------------------------
*/

it('binds MySQL to localhost only', function () {
    expect(opsSetting('mysql/construction.cnf', 'bind-address'))->toBe('127.0.0.1');
});

it('binds the X Protocol to localhost too, which bind-address does not cover', function () {
    // Verified on the local 8.4.3 server: mysqlx_bind_address defaults to `*`.
    // bind-address alone locks down port 3306 and leaves 33060 listening on
    // every interface — and this assertion is the only thing that would notice.
    expect(opsSetting('mysql/construction.cnf', 'mysqlx-bind-address'))->toBe('127.0.0.1');
});

it('runs MySQL in strict mode so money is refused rather than truncated', function () {
    // Non-strict MySQL silently truncates an out-of-range DECIMAL(18,4) and
    // stores it with a warning nobody reads. PLAN.md §4's money rule assumes a
    // value that does not fit is an error.
    expect(opsSetting('mysql/construction.cnf', 'sql_mode'))->toContain('STRICT_TRANS_TABLES');
});

it('lets a least-privilege user create the ledger triggers while binary logging is on', function () {
    // The finding. MySQL 8 enables binary logging by default, and with it on,
    // CREATE TRIGGER needs SUPER unless this is set. The ledger is append-only
    // BECAUSE of two triggers created by a migration, and the staging template
    // specifies a least-privilege database user — so without this the first
    // `migrate --force` on a real server fails on exactly the migration that
    // makes the ledger immutable.
    expect(opsSetting('mysql/construction.cnf', 'log_bin_trust_function_creators'))->toBe('1');
});

it('refuses LOAD DATA LOCAL', function () {
    expect(opsSetting('mysql/construction.cnf', 'local_infile'))->toBe('0');
});

/*
|--------------------------------------------------------------------------
| The runbook knows about all of it
|--------------------------------------------------------------------------
*/

it('documents every hardening file, so none of them is orphaned', function (string $path) {
    expect((string) file_get_contents(base_path('RUNBOOK.md')))->toContain($path);
})->with([
    'ops/ssh/sshd_config.d/00-construction.conf',
    'ops/fail2ban/jail.d/construction.local',
    'ops/php-fpm/construction.conf',
    'ops/mysql/construction.cnf',
    'ops/php/99-construction.ini',
]);
