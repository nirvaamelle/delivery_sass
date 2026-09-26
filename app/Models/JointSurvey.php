<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The survey both sides walk, and both sides sign.
 *
 * @property Carbon $surveyed_on
 * @property ?Carbon $contractor_signed_at
 * @property ?Carbon $client_signed_at
 */
#[Fillable([
    'accomplishment_id', 'number', 'surveyed_on',
    'client_representative', 'contractor_representative',
    'contractor_signed_at', 'contractor_signed_by_user_id',
    'client_signed_at', 'client_signed_by', 'remarks',
])]
class JointSurvey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'surveyed_on' => 'date',
            'contractor_signed_at' => 'datetime',
            'client_signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Accomplishment, $this>
     */
    public function accomplishment(): BelongsTo
    {
        return $this->belongsTo(Accomplishment::class);
    }

    /**
     * Joint means both. The client's signature is the one an invoice rests on,
     * but a survey the client signed and the contractor did not is not a joint
     * survey either — it is the client agreeing with themselves.
     */
    public function isFullySigned(): bool
    {
        return $this->contractor_signed_at !== null && $this->client_signed_at !== null;
    }
}
