<?php

namespace App\Models;

use Database\Factories\CostCodeFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'parent_id', 'code', 'name'])]
class CostCode extends Model
{
    /** @use HasFactory<CostCodeFactory> */
    use HasFactory;

    /**
     * A cost code may not become its own ancestor.
     *
     * A cycle in the WBS tree makes every rollup built on it non-terminating,
     * and the ledger groups by cost code. Guarded on save rather than left to
     * be discovered by an infinite loop at month-end close.
     */
    protected static function booted(): void
    {
        static::saving(function (CostCode $costCode): void {
            if ($costCode->parent_id === null) {
                return;
            }

            if ($costCode->exists && (int) $costCode->parent_id === (int) $costCode->getKey()) {
                throw new DomainException('A cost code cannot be its own parent.');
            }

            $ancestor = self::query()->find($costCode->parent_id);

            while ($ancestor !== null) {
                if ($costCode->exists && (int) $ancestor->getKey() === (int) $costCode->getKey()) {
                    throw new DomainException(
                        "Cost code {$costCode->code} cannot be its own ancestor."
                    );
                }

                $ancestor = $ancestor->parent_id === null
                    ? null
                    : self::query()->find($ancestor->parent_id);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<CostCode, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * The full WBS path from the root division down to this code.
     */
    public function wbsPath(string $separator = ' › '): string
    {
        $segments = [$this->code];
        $ancestor = $this->parent;

        while ($ancestor !== null) {
            array_unshift($segments, $ancestor->code);
            $ancestor = $ancestor->parent;
        }

        return implode($separator, $segments);
    }
}
