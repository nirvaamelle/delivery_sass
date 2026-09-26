<?php

namespace App\Models;

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerImmutableException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One posting in the project cost ledger.
 *
 * PLAN.md §1: "Every process ends in the same place: the project cost ledger."
 * This is that place, and the row is immutable once written — the database
 * enforces it with triggers, and the guard below turns the same refusal into a
 * readable exception rather than a raw SQL error.
 *
 * The casts below return enums and Carbon instances. Declared explicitly
 * because Larastan otherwise infers `string` from the column types, and the
 * poster passes these straight back into a typed signature when it reverses an
 * entry.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property LedgerCategory $category
 * @property CutoffType $cutoff_type
 * @property ?Carbon $posted_at
 * @property ?Carbon $document_date
 */
#[Fillable([
    'project_id',
    'cost_code_id',
    'project_code',
    'cost_code',
    'source_document_type',
    'source_document_id',
    'document_number',
    'category',
    'amount',
    'cutoff_type',
    'document_date',
    'posted_at',
    'description',
    'reverses_entry_id',
])]
class ProjectCostLedgerEntry extends Model
{
    protected $table = 'project_cost_ledger';

    /**
     * Append-only at the model layer as well as at the table.
     *
     * The triggers are the real defence; this exists so the common path fails
     * with a sentence somebody can act on.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LedgerImmutableException(
                'The project cost ledger is append-only. Post a reversing entry instead of editing a posting.'
            );
        });

        static::deleting(function (): never {
            throw new LedgerImmutableException(
                'The project cost ledger is append-only. A posting cannot be deleted; reverse it.'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => LedgerCategory::class,
            'cutoff_type' => CutoffType::class,
            'amount' => 'decimal:4',
            'document_date' => 'date',
            'posted_at' => 'datetime',
            'source_document_id' => 'integer',
            'reverses_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    /**
     * The document that caused this posting.
     *
     * @return MorphTo<Model, $this>
     */
    public function sourceDocument(): MorphTo
    {
        return $this->morphTo('sourceDocument', 'source_document_type', 'source_document_id');
    }

    /**
     * @return BelongsTo<ProjectCostLedgerEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
