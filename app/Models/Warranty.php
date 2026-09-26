<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A warranty certificate on file — F7's register.
 *
 * The certificate is a promise with an end date, which is the whole reason it
 * needs a row rather than an attachment: a claim is only a claim if the failure
 * happened while the promise was in force.
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon $received_at
 */
#[Fillable([
    'project_id', 'vendor_id', 'purchase_order_id', 'subcontract_id',
    'number', 'certificate_reference', 'scope',
    'starts_on', 'ends_on', 'received_at', 'received_by_user_id', 'remarks',
])]
class Warranty extends Model
{
    protected $table = 'warranties';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'received_at' => 'datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Subcontract, $this>
     */
    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class);
    }

    /**
     * @return HasMany<WarrantyClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }
}
