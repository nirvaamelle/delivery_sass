<?php

namespace App\Models;

use App\Domain\Cutoffs\CutoffType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One closable period for one cutoff type.
 *
 * The casts below turn these three into Carbon instances. Declared explicitly
 * because Larastan otherwise infers `string` from the column types, and the
 * resolver formats them as dates.
 *
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $cutoff_at
 */
#[Fillable(['project_id', 'cutoff_type', 'period_start', 'period_end', 'cutoff_at'])]
class CutoffCalendar extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cutoff_type' => CutoffType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'cutoff_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
