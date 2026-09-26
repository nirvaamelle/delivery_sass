<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's final-pay clearance.
 *
 * @property ?Carbon $cleared_at
 */
#[Fillable([
    'demobilization_id', 'employee_id', 'cleared_at', 'cleared_by_user_id', 'note',
])]
class DemobilizationClearance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cleared_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Demobilization, $this>
     */
    public function demobilization(): BelongsTo
    {
        return $this->belongsTo(Demobilization::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }
}
