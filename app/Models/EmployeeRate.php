<?php

namespace App\Models;

use App\Domain\Hris\PayBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What somebody earned, from when.
 *
 * @property PayBasis $basis
 * @property string $rate A decimal string, decrypted by the cast.
 * @property Carbon $effective_from
 */
#[Fillable([
    'employee_id', 'basis', 'rate', 'effective_from', 'set_by_user_id', 'remarks',
])]
class EmployeeRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basis' => PayBasis::class,
            // Encrypted, not decimal:4 — the column holds ciphertext. The money
            // contract survives because the value is a decimal string either
            // side of the encryption and never becomes a float.
            'rate' => 'encrypted',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
