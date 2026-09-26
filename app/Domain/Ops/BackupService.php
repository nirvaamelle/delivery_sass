<?php

namespace App\Domain\Ops;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Backup, restore, and the rehearsal that proves the restore works — P6-04, and
 * exit gate clause 2.
 *
 * PHASE-PLAN.md puts it in bold and then repeats it as its own gate clause,
 * which is the plan saying the same thing twice deliberately: **a backup nobody
 * has restored is not a backup.** It is a file whose contents nobody has
 * checked, and the first time anybody checks is the morning they need it.
 *
 * So the deliverable is not a dump command. It is a rehearsal — take a real
 * dump, load it into a scratch database, and assert the data and the guarantees
 * came back.
 *
 * **The guarantees, not just the rows.** The ledger is append-only because of
 * two MySQL triggers. A dump that dropped them would restore a table anybody
 * can edit, and nothing about the restored database would look wrong. So the
 * dump asks for routines and triggers explicitly, and the verification counts
 * them.
 *
 * **The password never reaches the command line.** `--password=` on argv is
 * readable by any user on the box for as long as the process runs — `ps` shows
 * it, and on a shared or compromised host that is the database password handed
 * over for free. Both processes are given a temporary defaults file instead,
 * written 0600 and deleted in a `finally` so it goes even when the dump throws.
 *
 * **The restore target is checked before anything is written.** This is the one
 * operation in the build capable of destroying a database. It refuses any name
 * without the scratch marker, and refuses the connection it is running on even
 * if that name carries one. A restore aimed at production by a tired hand at
 * 3am is the accident this phase exists to prevent, and `deploy.sh` was written
 * under the same rule in P0-16.
 */
class BackupService
{
    /**
     * A restore target must say what it is.
     *
     * A marker in the name rather than a list of forbidden names: a blocklist
     * is only as good as the last person who remembered to add to it, and the
     * databases worth protecting are the ones nobody thought of.
     */
    public const SCRATCH_MARKER = 'scratch';

    /**
     * Take a dump of the current database.
     *
     * @throws DomainException when mysqldump is unavailable or fails
     */
    public function dump(?string $path = null): string
    {
        $path ??= storage_path(sprintf(
            'app/backups/%s-%s.sql',
            DB::getDatabaseName(),
            now()->format('Ymd-His'),
        ));

        File::ensureDirectoryExists(dirname($path));

        $credentials = $this->writeCredentialsFile();

        try {
            $process = new Process([
                $this->binary('mysqldump'),
                // First argument, as mysqldump requires of this option.
                '--defaults-extra-file='.$credentials,
                // The guarantees, not just the rows. Without these the ledger
                // comes back as an ordinary, editable table.
                '--routines',
                '--triggers',
                '--events',
                // Consistent across tables without locking the site out. A dump
                // taken mid-payroll otherwise catches half a run.
                '--single-transaction',
                '--result-file='.$path,
                (string) DB::getDatabaseName(),
            ]);

            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput());

                throw new DomainException(sprintf(
                    'mysqldump failed: %s',
                    $error === '' ? $process->getOutput() : $error,
                ));
            }
        } finally {
            // In a finally, so a throwing dump does not leave the password on
            // disk until somebody notices.
            @unlink($credentials);
        }

        return $path;
    }

    /**
     * Load a dump into a scratch database.
     *
     * @throws DomainException when the target is not a scratch database, is the
     *                         live connection, or the dump is missing or empty
     */
    public function restore(string $path, string $database): void
    {
        $this->assertSafeTarget($database);

        if (! File::exists($path)) {
            throw new DomainException(sprintf(
                'The dump %s does not exist. There is nothing to restore.',
                $path,
            ));
        }

        if (File::size($path) === 0) {
            /*
             * The classic silent backup failure: the cron ran, the file exists,
             * the disk was full. Restoring it would drop the target and put
             * nothing back — which, on the wrong target, is the entire disaster
             * this class exists to prevent.
             */
            throw new DomainException(sprintf(
                'The dump %s is empty. A zero-byte dump is a backup that failed quietly.',
                $path,
            ));
        }

        DB::statement('DROP DATABASE IF EXISTS '.$database);
        DB::statement('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $credentials = $this->writeCredentialsFile();

        try {
            $command = sprintf(
                '%s --defaults-extra-file=%s %s < %s',
                escapeshellarg($this->binary('mysql')),
                escapeshellarg($credentials),
                escapeshellarg($database),
                escapeshellarg($path),
            );

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new DomainException(sprintf(
                    'The restore failed: %s',
                    trim($process->getErrorOutput()),
                ));
            }
        } finally {
            @unlink($credentials);
        }
    }

    /**
     * Restore, then count what came back.
     *
     * Exit gate clause 2 is evidence somebody has to produce. "It worked" is
     * not evidence; a table count, a row count and the trigger count are.
     *
     * @return array{tables: int, projects: int, triggers: int, dump: string, bytes: int}
     */
    public function verify(string $path, string $database): array
    {
        $this->restore($path, $database);

        $tables = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database],
        );

        $triggers = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?',
            [$database, 'project_cost_ledger'],
        );

        $projects = DB::selectOne('SELECT COUNT(*) AS total FROM '.$database.'.projects');

        return [
            'tables' => (int) ($tables->total ?? 0),
            'projects' => (int) ($projects->total ?? 0),
            'triggers' => (int) ($triggers->total ?? 0),
            'dump' => $path,
            'bytes' => (int) File::size($path),
        ];
    }

    /**
     * @throws DomainException
     */
    private function assertSafeTarget(string $database): void
    {
        /*
         * The connected database first, and the order is deliberate. It is the
         * most dangerous target and the most specific refusal, so it should be
         * the message somebody sees — a name that happens to carry the marker
         * would otherwise sail past this check entirely.
         */
        if ($database === DB::getDatabaseName()) {
            throw new DomainException(sprintf(
                'Refusing to restore onto "%s": it is the database this process is currently connected to. A restore drops the target first.',
                $database,
            ));
        }

        if (! str_contains($database, self::SCRATCH_MARKER)) {
            throw new DomainException(sprintf(
                'Refusing to restore onto "%s": a restore target must be a scratch database, with "%s" in its name. A restore drops the target first.',
                $database,
                self::SCRATCH_MARKER,
            ));
        }
    }

    /**
     * Write the credentials somewhere only this user can read them.
     *
     * `--password=` on the command line is visible in `ps` to every user on the
     * box for as long as the process runs. A defaults file is the documented
     * alternative, and it is created 0600 before anything is written into it —
     * created first and chmod'd second would leave a window where the password
     * is world-readable, which is the whole bug in miniature.
     */
    private function writeCredentialsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbcred');

        if ($path === false) {
            throw new DomainException('Could not create a temporary credentials file for the database client.');
        }

        // Before the contents, not after.
        @chmod($path, 0600);

        File::put($path, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            (string) config('database.connections.mysql.host'),
            (string) config('database.connections.mysql.port'),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
        ));

        return $path;
    }

    /**
     * The client binary, allowing an explicit path for hosts that do not carry
     * it on PATH — which is every Laragon shell this build has run in.
     */
    private function binary(string $name): string
    {
        $configured = config('ops.mysql_bin_path');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\').DIRECTORY_SEPARATOR.$name;
        }

        return $name;
    }
}
