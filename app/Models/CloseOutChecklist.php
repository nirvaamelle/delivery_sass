<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slide 9's close-out checklist — the document the project closes on.
 *
 * @property Carbon $opened_at
 */
#[Fillable(['project_id', 'number', 'opened_at', 'opened_by_user_id'])]
class CloseOutChecklist extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<CloseOutChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CloseOutChecklistItem::class)->orderBy('sequence');
    }
}
