<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| Backup and restore — P6-04, exit gate clause 2
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Phase 6's build list puts it in bold: "**A backup restored onto
| a scratch database to prove it works.**" And the exit gate repeats it as its
| own clause, which is the plan saying the same thing twice on purpose.
|
| **A backup nobody has restored is not a backup.** It is a file whose contents
| nobody has checked, and the first time anybody checks is the morning they need
| it. So the deliverable here is not a dump command — it is a rehearsal that
| takes a real dump, loads it into a scratch database, and asserts the data came
| back.
|
| **The restore target is asserted before anything is written.** This is the one
| command in the build capable of destroying a database, and the guard is that
| it will not run against a database whose name it was not given explicitly and
| which does not carry the scratch marker. A restore aimed at production by a
| tired hand at 3am is precisely the accident this phase exists to prevent.
|
| The rehearsal runs against MySQL, because the ledger's append-only guarantee
| is a pair of MySQL triggers and a dump that lost them would restore a table
| anybody can edit.
|
*/

afterEach(function () {
    // Committed on purpose (see `seedRestorableRow`), so the enclosing
    // transaction will not take them back.
    forgetRestorableRows();

    DB::statement('DROP DATABASE IF EXISTS '.scratchDatabase());

    foreach (File::glob(storage_path('app/backups/*.sql')) as $file) {
        File::delete($file);
    }
});

it('writes a dump containing the schema and the data', function () {
    seedRestorableRow('PRJ-BACKUP-1');

    $path = backups()->dump();

    expect(File::exists($path))->toBeTrue();

    $sql = File::get($path);

    expect($sql)->toContain('CREATE TABLE')
        ->toContain('projects')
        ->toContain('PRJ-BACKUP-1');
});

it('includes the ledger triggers in the dump', function () {
    // The ledger is append-only because of two MySQL triggers. A dump that
    // dropped them would restore a table anybody can edit, and nothing about
    // the restored database would look wrong.
    $sql = File::get(backups()->dump());

    expect($sql)->toContain('TRIGGER');
});

it('restores onto a scratch database and brings the data back', function () {
    // The clause, in one test: dumped, restored elsewhere, and the row is
    // there.
    seedRestorableRow('PRJ-BACKUP-2');
    $path = backups()->dump();

    backups()->restore($path, scratchDatabase());

    expect(scratchQuery(scratchDatabase(), 'select code from projects'))->toContain('PRJ-BACKUP-2');
});

it('restores the ledger triggers, not only the tables', function () {
    // What "verified" has to mean. A restore that brought the rows back and
    // left the guarantees behind would pass any test that only counted rows.
    $path = backups()->dump();

    backups()->restore($path, scratchDatabase());

    $triggers = DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?',
        [scratchDatabase(), 'project_cost_ledger'],
    );

    expect(count($triggers))->toBe(2);
});

it('refuses to restore onto a database whose name is not marked scratch', function () {
    // The guard that matters. This is the one command in the build capable of
    // destroying a database, and a restore aimed at production by a tired hand
    // at 3am is the accident this phase exists to prevent.
    $path = backups()->dump();

    expect(fn () => backups()->restore($path, 'construction'))
        ->toThrow(DomainException::class, 'scratch');
});

it('refuses to restore onto the database it is connected to', function () {
    // Belt and braces: even a name carrying the marker cannot be the live
    // connection, or a misconfigured environment restores over itself.
    $path = backups()->dump();

    expect(fn () => backups()->restore($path, DB::getDatabaseName()))
        ->toThrow(DomainException::class, 'currently connected');
});

it('refuses to restore a file that is not there', function () {
    expect(fn () => backups()->restore(storage_path('app/backups/nothing.sql'), scratchDatabase()))
        ->toThrow(DomainException::class, 'does not exist');
});

it('refuses to restore an empty dump', function () {
    // A zero-byte dump is the classic silent backup failure — the cron ran, the
    // file exists, the disk was full. Restoring it would drop the scratch
    // database and put nothing back, and on the wrong target that is the whole
    // disaster.
    $empty = storage_path('app/backups/empty-'.uniqid().'.sql');
    File::ensureDirectoryExists(dirname($empty));
    File::put($empty, '');

    expect(fn () => backups()->restore($empty, scratchDatabase()))
        ->toThrow(DomainException::class, 'empty');
});

it('rehearses the whole thing in one command', function () {
    // What somebody actually runs, monthly, to keep clause 2 true rather than
    // true once.
    seedRestorableRow('PRJ-REHEARSAL');

    artisan('backup:rehearse', ['--scratch' => scratchDatabase()])
        ->assertExitCode(0);
});

it('fails the rehearsal loudly when the restore proves nothing', function () {
    // A rehearsal that cannot fail is theatre. Pointed at a name it must
    // refuse, the command exits non-zero rather than reporting success.
    artisan('backup:rehearse', ['--scratch' => 'construction'])
        ->assertExitCode(1);
});

it('names the verification in a way an auditor can read', function () {
    // Exit gate clause 2 is evidence somebody has to produce. "It worked" is
    // not evidence; the row count and the table count are.
    seedRestorableRow('PRJ-EVIDENCE');
    $path = backups()->dump();

    $result = backups()->verify($path, scratchDatabase());

    expect($result['tables'])->toBeGreaterThan(50)
        ->and($result['projects'])->toBeGreaterThan(0)
        ->and($result['triggers'])->toBe(2);
});
