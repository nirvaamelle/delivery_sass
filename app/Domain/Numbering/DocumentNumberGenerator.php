<?php

namespace App\Domain\Numbering;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Gapless, per-document-type, per-year document numbers — PLAN.md §3.
 *
 * The number is taken from a counter row locked with SELECT … FOR UPDATE inside
 * a transaction. Two things follow from that, and both are the point:
 *
 *   - Concurrency. A second request for the same type and year blocks on the
 *     lock until the first commits, so it cannot read a stale counter and issue
 *     a duplicate. MAX(id) + 1 has no such protection, which is why PLAN.md
 *     forbids it by name.
 *   - Gaplessness. The counter advances inside the CALLER's transaction, so a
 *     document insert that rolls back takes its number with it instead of
 *     leaving a hole. An auditor reading PR-2026-00141 followed by
 *     PR-2026-00143 is entitled to ask what happened to 142.
 *
 * Callers must therefore open the transaction that also writes the document:
 *
 *     DB::transaction(function () use ($numbering) {
 *         $pr = PurchaseRequisition::create([
 *             'number' => $numbering->next('PR'),
 *             // …
 *         ]);
 *     });
 */
class DocumentNumberGenerator
{
    /**
     * A bare uppercase prefix — no dashes, no lowercase, 2 to 10 characters.
     * The prefix is parsed back out of printed references across every chain,
     * so the shape has to stay predictable.
     */
    private const TYPE_PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    /**
     * The width of the sequence segment: PR-2026-00142.
     */
    private const SEQUENCE_WIDTH = 5;

    /**
     * Issue the next number for a document type.
     *
     * @throws InvalidArgumentException when the document type is not a bare
     *                                  uppercase prefix
     */
    public function next(string $documentType, ?int $year = null): string
    {
        $type = $this->normaliseType($documentType);
        $year ??= (int) now()->year;

        return DB::transaction(function () use ($type, $year): string {
            // Create the counter if this is the first document of its type this
            // year. insertOrIgnore leans on the unique key, so a concurrent
            // first-issue loses the race harmlessly instead of erroring.
            DB::table('document_sequences')->insertOrIgnore([
                'document_type' => $type,
                'year' => $year,
                'next_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = DB::table('document_sequences')
                ->where('document_type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                throw new RuntimeException(
                    "Counter row for {$type}-{$year} vanished between insert and lock."
                );
            }

            $number = (int) $counter->next_number;

            DB::table('document_sequences')
                ->where('id', $counter->id)
                ->update([
                    'next_number' => $number + 1,
                    'updated_at' => now(),
                ]);

            return $this->format($type, $year, $number);
        });
    }

    private function normaliseType(string $documentType): string
    {
        $type = trim($documentType);

        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(
                "Document type must be a bare uppercase prefix of 2 to 10 characters, got \"{$documentType}\"."
            );
        }

        return $type;
    }

    private function format(string $type, int $year, int $number): string
    {
        return sprintf(
            '%s-%d-%0'.self::SEQUENCE_WIDTH.'d',
            $type,
            $year,
            $number
        );
    }
}
