<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A queued job that failed, and who dealt with it.
 *
 * @property int $occurrences
 * @property Carbon $first_failed_at
 * @property Carbon $last_failed_at
 * @property ?Carbon $acknowledged_at
 */
#[Fillable([
    'job_name', 'connection', 'queue', 'exception_message', 'addressed_to_role',
    'occurrences', 'first_failed_at', 'last_failed_at',
    'acknowledged_at', 'acknowledged_by_user_id', 'resolution',
])]
class JobFailureAlert extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
