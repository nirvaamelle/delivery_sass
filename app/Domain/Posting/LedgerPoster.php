<?php

namespace App\Domain\Posting;

use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffNotConfiguredException;
use App\Domain\Cutoffs\CutoffResolver;
use App\Domain\Cutoffs\CutoffType;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code permitted to write project_cost_ledger — PLAN.md §3.
 *
 * "Every process ends in the same place: the project cost ledger." Four chains,
 * one destination, and one door into it. Concentrating every write here is what
 * makes the invariants below true of every row rather than true of the rows
 * whoever wrote them remembered to check:
 *
 *   - the project code and cost code are snapshotted, so history cannot be
 *     restated by editing a reference;
 *   - the source document is always recorded, so a P&L line traces back to the
 *     receiving report or payslip behind it;
 *   - the cutoff calendar is consulted on every posting, against the right
 *     calendar for the chain (F3);
 *   - the cost code belongs to the project's own organization.
 *
 * Corrections are reversing entries. The table refuses UPDATE and DELETE
 * outright, so there is no other way to change what a posting says.
 */
class LedgerPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly CutoffResolver $cutoffs,
    ) {}

    /**
     * Write one posting.
     *
     * @throws InvalidArgumentException when the amount is not a non-zero decimal
     * @throws CrossOrganizationPostingException when the cost code is another organization's
     * @throws CutoffClosedException when the period has closed
     * @throws CutoffNotConfiguredException when no calendar covers the date
     */
    public function post(
        Project $project,
        CostCode $costCode,
        LedgerCategory $category,
        string $amount,
        Model $sourceDocument,
        string $documentNumber,
        CutoffType $cutoffType,
        CarbonInterface $documentDate,
        ?string $description = null,
        ?ProjectCostLedgerEntry $reverses = null,
    ): ProjectCostLedgerEntry {
        $amount = $this->assertAmount($amount);
        $this->assertSameOrganization($project, $costCode);

        // The cutoff check happens before anything is written, and it resolves
        // against the calendar for THIS chain. A payroll posting judged by the
        // OPEX calendar is judged by the wrong date entirely.
        $this->cutoffs->assertOpen($cutoffType, $documentDate, $project);

        return DB::transaction(fn (): ProjectCostLedgerEntry => ProjectCostLedgerEntry::query()->create([
            'project_id' => $project->getKey(),
            'cost_code_id' => $costCode->getKey(),
            'project_code' => $project->code,
            'cost_code' => $costCode->code,
            'source_document_type' => $sourceDocument->getMorphClass(),
            'source_document_id' => $sourceDocument->getKey(),
            'document_number' => $documentNumber,
            'category' => $category,
            'amount' => $amount,
            'cutoff_type' => $cutoffType,
            'document_date' => $documentDate,
            'posted_at' => now(),
            'description' => $description,
            'reverses_entry_id' => $reverses?->getKey(),
        ]));
    }

    /**
     * Cancel a posting with its contra entry.
     *
     * The original stays exactly as it was written and the pair nets to zero.
     * That is what an auditor expects to find — not a row that quietly changed
     * value between two readings of the same report.
     *
     * @throws InvalidArgumentException when the entry has already been reversed
     */
    public function reverse(ProjectCostLedgerEntry $entry, string $reason): ProjectCostLedgerEntry
    {
        if ($this->isReversed($entry)) {
            throw new InvalidArgumentException(sprintf(
                'Posting %s has already been reversed. Reversing it twice would turn a correction into a credit.',
                $entry->document_number
            ));
        }

        return $this->post(
            project: $entry->project()->sole(),
            costCode: $entry->costCode()->sole(),
            category: $entry->category,
            amount: bcsub('0', (string) $entry->amount, self::SCALE),
            sourceDocument: $entry,
            documentNumber: $entry->document_number,
            cutoffType: $entry->cutoff_type,
            documentDate: $entry->document_date ?? now(),
            description: sprintf('Reversal of %s: %s', $entry->document_number, $reason),
            reverses: $entry,
        );
    }

    /**
     * The exact total of a project's ledger, reversals included.
     *
     * Summed with bcadd rather than SQL SUM() so the result is a decimal string
     * end to end. The P&L is built on this number; a float here would put a
     * rounding error into every report downstream.
     */
    public function totalFor(Project $project, ?LedgerCategory $category = null): string
    {
        $amounts = ProjectCostLedgerEntry::query()
            ->where('project_id', $project->getKey())
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->pluck('amount');

        $total = '0';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, self::SCALE);
        }

        return bcadd($total, '0', self::SCALE);
    }

    public function isReversed(ProjectCostLedgerEntry $entry): bool
    {
        return ProjectCostLedgerEntry::query()
            ->where('reverses_entry_id', $entry->getKey())
            ->exists();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertAmount(string $amount): string
    {
        $trimmed = trim($amount);

        if (preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A posting amount must be a decimal string, got "%s".',
                $amount
            ));
        }

        // A zero posting is noise in a ledger that exists to be read: it adds a
        // row to every report and changes no total.
        if (bccomp($trimmed, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('A posting of zero has nothing to record.');
        }

        return $trimmed;
    }

    /**
     * @throws CrossOrganizationPostingException
     */
    private function assertSameOrganization(Project $project, CostCode $costCode): void
    {
        if ((int) $project->organization_id !== (int) $costCode->organization_id) {
            throw new CrossOrganizationPostingException(sprintf(
                'Cost code %s belongs to another organization than project %s.',
                $costCode->code,
                $project->code
            ));
        }
    }
}
