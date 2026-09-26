<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Slide 9 step 1 — the works are usable for their intended purpose.
 *
 * One per project, enforced by a unique key rather than by the service: a
 * second certificate would give the defects liability period two start dates.
 *
 * @property Carbon $certified_on
 */
#[Fillable([
    'project_id', 'number', 'certified_on', 'certified_by_user_id',
    'client_representative', 'remarks',
])]
class SubstantialCompletion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'certified_on' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by_user_id');
    }

    /**
     * @return HasOne<Punchlist, $this>
     */
    public function punchlist(): HasOne
    {
        return $this->hasOne(Punchlist::class);
    }
}
