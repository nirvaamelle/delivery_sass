<?php

namespace App\Models;

use App\Domain\Procurement\SubcontractStatus;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Works let to a subcontractor.
 *
 * @property string $contract_amount DECIMAL(18,4), surfaced as a string.
 * @property SubcontractStatus $status
 * @property Carbon $works_start
 * @property Carbon $works_end
 */
#[Fillable([
    'project_id', 'vendor_id', 'number', 'status', 'scope_of_work',
    'contract_amount', 'works_start', 'works_end', 'performance_bond_id',
])]
class Subcontract extends Model
{
    private const SERVICE_ONLY = [
        'project_id', 'vendor_id', 'number', 'contract_amount', 'performance_bond_id',
    ];

    private static bool $withinService = false;

    public static function mutate(Closure $callback): mixed
    {
        self::$withinService = true;

        try {
            return $callback();
        } finally {
            self::$withinService = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (Subcontract $subcontract): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($subcontract->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Subcontract %s: "%s" is part of the award record and cannot be changed by a direct update.',
                        $subcontract->number ?? '(new)',
                        $column,
                    ));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubcontractStatus::class,
            'contract_amount' => 'decimal:4',
            'works_start' => 'date',
            'works_end' => 'date',
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
     * @return BelongsTo<VendorBond, $this>
     */
    public function performanceBond(): BelongsTo
    {
        return $this->belongsTo(VendorBond::class, 'performance_bond_id');
    }
}
