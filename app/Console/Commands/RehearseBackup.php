<?php

namespace App\Console\Commands;

use App\Domain\Ops\BackupService;
use DomainException;
use Illuminate\Console\Command;

/**
 * `php artisan backup:rehearse`
 *
 * Exit gate clause 2, as something somebody runs monthly rather than something
 * somebody did once. A backup nobody has restored since March is a backup
 * nobody has restored.
 *
 * It prints the evidence rather than "OK": the table count, the row count and
 * the trigger count are what an auditor can put in a working paper, and what
 * B4 will ask for.
 */
class RehearseBackup extends Command
{
    protected $signature = 'backup:rehearse
                            {--scratch=construction_scratch : Scratch database to restore into; the name must contain "scratch"}
                            {--keep : Leave the dump on disk afterwards}';

    protected $description = 'Take a backup, restore it onto a scratch database, and report what came back';

    public function handle(BackupService $backups): int
    {
        $scratch = (string) $this->option('scratch');

        try {
            $this->info('Taking a dump…');
            $path = $backups->dump();

            $this->info(sprintf('Restoring onto %s…', $scratch));
            $result = $backups->verify($path, $scratch);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Check', 'Result'],
            [
                ['Dump', $result['dump']],
                ['Dump size (bytes)', number_format($result['bytes'])],
                ['Tables restored', (string) $result['tables']],
                ['Projects restored', (string) $result['projects']],
                ['Ledger triggers restored', $result['triggers'].' of 2'],
            ],
        );

        if ($result['triggers'] !== 2) {
            // Every row can be back and the ledger still come back editable.
            // That is a restore that passes a row count and loses the guarantee
            // the whole audit trail rests on.
            $this->error('The ledger triggers did not survive the restore. The append-only guarantee is not in this backup.');

            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            @unlink($result['dump']);
        }

        $this->info('Restore verified.');

        return self::SUCCESS;
    }
}
