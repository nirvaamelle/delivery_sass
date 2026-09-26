<?php

namespace App\Domain\Hris;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\DisbursementBatch;
use App\Models\DisbursementItem;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Disbursement — slide 7's step 8: "bank upload OR cash payout with
 * acknowledgment."
 *
 * **The "or" is the design.** The two methods prove payment in completely
 * different ways, and collapsing them into one `paid` flag loses the only
 * evidence the cash half has:
 *
 *   - a **bank upload** is evidenced by the file that went and the reference
 *     that came back. Preparing the file and transmitting it releases the money;
 *     nobody signs anything, and requiring a signature would make every bank
 *     payroll wait on paperwork that does not exist;
 *   - a **cash payout** is evidenced by a signature and nothing else. It is not
 *     released until somebody acknowledges it, because cash with no
 *     acknowledgment is indistinguishable from cash that never left the drawer.
 *
 * The register must be APPROVED before either. F10's variance gate sits between
 * computed and approved, so disbursing a computed register would walk past it.
 *
 * The bank file goes to the **private** disk. It is every employee's name,
 * account number and net pay in one document; on the public disk it is one
 * guessed URL away from being everybody's payslip.
 */
class DisbursementService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Build the batch for an approved register.
     *
     * @throws DomainException when the register is not approved, has no lines, or
     *                         a bank upload is missing an account number
     */
    public function prepare(PayrollRun $run, DisbursementMethod $method, ?User $by = null): DisbursementBatch
    {
        if ($run->status !== PayrollRunStatus::Approved) {
            throw new DomainException(sprintf(
                'Payroll run %s is %s. Only an approved register can be disbursed — the variance review sits between computed and approved.',
                $run->number,
                $run->status->value,
            ));
        }

        $lines = $run->lines()->with('employee')->get();

        if ($lines->isEmpty()) {
            throw new DomainException(sprintf('Payroll run %s has no lines to pay.', $run->number));
        }

        if ($method === DisbursementMethod::BankUpload) {
            $missing = $lines
                ->filter(fn (PayrollLine $line): bool => trim((string) $line->employee->bank_account_number) === '')
                ->map(fn (PayrollLine $line): string => $line->employee->employee_number)
                ->values()
                ->all();

            if ($missing !== []) {
                // Reported, never skipped. A bank file quietly one line short is
                // somebody not paid, discovered when they say so.
                throw new DomainException(sprintf(
                    'No bank account on file for: %s. A bank upload one line short is somebody not paid.',
                    implode(', ', $missing),
                ));
            }
        }

        return DB::transaction(function () use ($run, $method, $lines, $by): DisbursementBatch {
            $total = '0.0000';

            foreach ($lines as $line) {
                $total = Money::sum($total, (string) $line->net_pay);
            }

            $batch = DisbursementBatch::query()->create([
                'payroll_run_id' => $run->getKey(),
                'number' => $this->numbering->next('DSB'),
                'method' => $method,
                'total_amount' => $total,
                'prepared_at' => now(),
                'prepared_by_user_id' => $by?->getKey(),
            ]);

            foreach ($lines as $line) {
                $batch->items()->create([
                    'payroll_line_id' => $line->getKey(),
                    'employee_id' => $line->employee_id,
                    'amount' => (string) $line->net_pay,
                ]);
            }

            if ($method === DisbursementMethod::BankUpload) {
                $batch->update(['file_path' => $this->writeBankFile($batch->refresh(), $lines)]);
            }

            return $batch->refresh();
        });
    }

    /**
     * The bank half's release: the file went, and this is what came back.
     *
     * @throws DomainException when the batch is not a bank upload, already
     *                         transmitted, or carries no bank reference
     */
    public function markTransmitted(DisbursementBatch $batch, string $bankReference, ?User $by = null): DisbursementBatch
    {
        if ($batch->method !== DisbursementMethod::BankUpload) {
            throw new DomainException('Only a bank upload is transmitted; a cash payout is released by acknowledgment.');
        }

        if ($batch->transmitted_at !== null) {
            throw new DomainException(sprintf('Batch %s was already transmitted.', $batch->number));
        }

        if (trim($bankReference) === '') {
            // The reference IS the evidence for this half. Without it the batch
            // records that a file was made, not that money moved.
            throw new DomainException('A transmitted batch needs the bank reference that came back.');
        }

        return DB::transaction(function () use ($batch, $bankReference, $by): DisbursementBatch {
            $batch->update([
                'transmitted_at' => now(),
                'bank_reference' => $bankReference,
                'transmitted_by_user_id' => $by?->getKey(),
            ]);

            $batch->items()->whereNull('released_at')->update(['released_at' => now()]);

            $this->settleRun($batch->refresh());

            return $batch->refresh();
        });
    }

    /**
     * The cash half's release: somebody signed for it.
     *
     * @throws DomainException when already acknowledged, nobody is named, or the
     *                         batch is not a cash payout
     */
    public function acknowledge(
        DisbursementItem $item,
        string $signatory,
        CarbonInterface $acknowledgedOn,
        ?User $witness = null,
    ): DisbursementItem {
        $batch = $item->batch()->sole();

        if ($batch->method !== DisbursementMethod::CashPayout) {
            throw new DomainException('Only a cash payout is acknowledged; a bank upload is released by its file.');
        }

        if ($item->released_at !== null) {
            throw new DomainException('That payment was already acknowledged. A second acknowledgment is a second release of the same money.');
        }

        if (trim($signatory) === '') {
            throw new DomainException('An acknowledgment needs the name of whoever received the money. A tick is not evidence anybody was paid.');
        }

        return DB::transaction(function () use ($item, $batch, $signatory, $acknowledgedOn, $witness): DisbursementItem {
            $item->update([
                'released_at' => now(),
                'acknowledged_on' => $acknowledgedOn,
                'acknowledged_by' => $signatory,
                'witnessed_by_user_id' => $witness?->getKey(),
            ]);

            $this->settleRun($batch);

            return $item->refresh();
        });
    }

    /**
     * How many payments in this batch have not been released.
     */
    public function outstandingFor(DisbursementBatch $batch): int
    {
        return $batch->items()->whereNull('released_at')->count();
    }

    /**
     * Mark the register released once every payment in it has been.
     *
     * Partly-paid is a real state and the register stays approved through it:
     * closing a cutoff that still owes somebody money is how the person who has
     * not been paid stops appearing on anybody's list.
     */
    private function settleRun(DisbursementBatch $batch): void
    {
        if ($this->outstandingFor($batch) > 0) {
            return;
        }

        $run = $batch->run()->sole();

        if ($run->status === PayrollRunStatus::Approved) {
            $run->update([
                'status' => PayrollRunStatus::Released,
                'released_at' => now(),
            ]);
        }
    }

    /**
     * Write the bank file and return its path on the private disk.
     *
     * @param  Collection<int, PayrollLine>  $lines
     */
    private function writeBankFile(DisbursementBatch $batch, $lines): string
    {
        $rows = ['employee_number,account_number,amount,reference'];

        foreach ($lines as $line) {
            $rows[] = implode(',', [
                $line->employee->employee_number,
                // Decrypted here and only here, at the point the bank needs it.
                (string) $line->employee->bank_account_number,
                (string) $line->net_pay,
                $batch->number,
            ]);
        }

        $path = sprintf('disbursements/%s.csv', $batch->number);

        Storage::disk('local')->put($path, implode("\n", $rows)."\n");

        return $path;
    }
}
