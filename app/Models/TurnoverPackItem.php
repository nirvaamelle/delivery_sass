<?php

namespace App\Models;

use App\Domain\Closeout\TurnoverItemSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One document on the turnover checklist.
 *
 * There is no status column. `filed_at` is the truth and the name beside it is
 * what the close-out report is made of — the same rule P5-01 applied to
 * punchlist items, and for the same reason: a boolean is a second place for one
 * fact to live, and it is the one that survives an import.
 *
 * @property TurnoverItemSource $source
 * @property bool $required
 * @property ?Carbon $filed_at
 */
#[Fillable([
    'turnover_pack_id', 'sequence', 'document_key', 'label', 'required', 'source',
    'reference', 'filed_at', 'filed_by_user_id', 'remarks',
])]
class TurnoverPackItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'source' => TurnoverItemSource::class,
            'filed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TurnoverPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(TurnoverPack::class, 'turnover_pack_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }
}
