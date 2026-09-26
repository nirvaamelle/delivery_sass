<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site where goods are held — spec §4.1.
 *
 * A register, not a hierarchy: bins and directed putaway are out of scope
 * until a client needs them. What the rest of logistics needs from this table
 * today is a stable id — gate visits, pick tasks, manning allocations and trip
 * plans all hang off it.
 *
 * @property bool $is_active
 */
#[Fillable(['organization_id', 'code', 'name', 'address', 'is_active'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
