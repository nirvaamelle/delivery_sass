<?php

namespace App\Models;

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\Ownership;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A machine, a tool or an IT asset — the register F5 says does not exist.
 *
 * @property string $acquisition_cost DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $salvage_value
 * @property int $useful_life_months
 * @property Ownership $ownership
 * @property EquipmentStatus $status
 * @property DepreciationMethod $depreciation_method
 * @property ?Carbon $acquired_on
 */
#[Fillable([
    'organization_id', 'code', 'description', 'category', 'serial_number',
    'ownership', 'status', 'purchase_order_id', 'acquisition_cost',
    'salvage_value', 'acquired_on', 'useful_life_months', 'depreciation_method',
])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    /**
     * Laravel would pluralise this to `equipments`.
     */
    protected $table = 'equipment';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ownership' => Ownership::class,
            'status' => EquipmentStatus::class,
            'depreciation_method' => DepreciationMethod::class,
            'acquisition_cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'acquired_on' => 'date',
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<EquipmentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    /**
     * @return HasMany<EquipmentCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(EquipmentCost::class);
    }

    /**
     * @return HasMany<DepreciationSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(DepreciationSchedule::class);
    }
}
