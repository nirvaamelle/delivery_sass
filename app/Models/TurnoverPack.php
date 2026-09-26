<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slide 9 step 3 — the pack handed to the client, and their acceptance of it.
 *
 * Acceptance is three columns and not a flag: when, by whom on our side, and
 * the name of the person signing for the client. The close-out report is made
 * of exactly that.
 *
 * @property Carbon $assembled_at
 * @property ?Carbon $accepted_at
 */
#[Fillable([
    'project_id', 'substantial_completion_id', 'number',
    'assembled_at', 'assembled_by_user_id',
    'accepted_at', 'accepted_by_user_id', 'client_representative', 'acceptance_remarks',
])]
class TurnoverPack extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assembled_at' => 'datetime',
            'accepted_at' => 'datetime',
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
     * @return BelongsTo<SubstantialCompletion, $this>
     */
    public function substantialCompletion(): BelongsTo
    {
        return $this->belongsTo(SubstantialCompletion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @return HasMany<TurnoverPackItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TurnoverPackItem::class)->orderBy('sequence');
    }
}
