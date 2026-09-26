<?php

namespace App\Models;

use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier or subcontractor.
 *
 * @property VendorStatus $status
 * @property ?VendorSuspensionReason $status_reason
 * @property ?Carbon $status_changed_at
 */
#[Fillable([
    'organization_id', 'code', 'name', 'tin', 'contact_person', 'email', 'phone', 'address',
    'status', 'status_reason', 'status_notes', 'status_changed_by_user_id', 'status_changed_at',
    'bank_name', 'bank_account_name', 'bank_account_number',
])]
#[Hidden(['bank_account_number'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            'status_reason' => VendorSuspensionReason::class,
            'status_changed_at' => 'datetime',

            // PLAN.md §3, encrypted at rest. One line per column, done in the
            // first migration rather than retrofitted — retrofitting means
            // rewriting every existing row and hoping none was missed.
            'bank_name' => 'encrypted',
            'bank_account_name' => 'encrypted',
            'bank_account_number' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<VendorAccreditation, $this>
     */
    public function accreditations(): HasMany
    {
        return $this->hasMany(VendorAccreditation::class);
    }

    /**
     * @return HasMany<VendorValidationVisit, $this>
     */
    public function validationVisits(): HasMany
    {
        return $this->hasMany(VendorValidationVisit::class);
    }

    /**
     * @return HasMany<VendorScorecard, $this>
     */
    public function scorecards(): HasMany
    {
        return $this->hasMany(VendorScorecard::class);
    }

    /**
     * @return HasMany<WarrantyClaim, $this>
     */
    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    /**
     * @return HasMany<VendorBond, $this>
     */
    public function bonds(): HasMany
    {
        return $this->hasMany(VendorBond::class);
    }
}
