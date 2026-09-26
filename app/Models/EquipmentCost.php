<?php

namespace App\Models;

use App\Domain\Equipment\EquipmentCostType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fuel, repair, maintenance — the OPEX inputs F5 says cannot be built without a
 * register.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property EquipmentCostType $type
 * @property Carbon $incurred_on
 */
#[Fillable([
    'equipment_id', 'project_id', 'cost_code_id', 'equipment_assignment_id',
    'type', 'amount', 'incurred_on', 'reference', 'remarks', 'recorded_by_user_id',
])]
class EquipmentCost extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EquipmentCostType::class,
            'amount' => 'decimal:4',
            'incurred_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    /**
     * @return BelongsTo<EquipmentAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EquipmentAssignment::class, 'equipment_assignment_id');
    }
}
