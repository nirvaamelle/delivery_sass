<?php

namespace App\Models;

use App\Domain\Projects\ScopedToProject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slide 9 step 2 — the defects outstanding at substantial completion.
 *
 * Closing it is a named act with a note, never a flag that flips when the last
 * item happens to be cleared. An empty punchlist has no open items either, and
 * treating that as cleared would let turnover through on a site nobody walked.
 *
 * @property Carbon $issued_on
 * @property ?Carbon $closed_at
 */
#[Fillable([
    'substantial_completion_id', 'project_id', 'number', 'issued_on', 'issued_by_user_id',
    'closed_at', 'closed_by_user_id', 'closure_note',
])]
class Punchlist extends Model
{
    use ScopedToProject;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubstantialCompletion, $this>
     */
    public function substantialCompletion(): BelongsTo
    {
        return $this->belongsTo(SubstantialCompletion::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany<PunchlistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PunchlistItem::class);
    }
}
