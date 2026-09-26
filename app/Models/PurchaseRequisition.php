<?php

namespace App\Models;

use App\Domain\Requisitions\RequisitionStatus;
use Database\Factories\PurchaseRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The first document in the procurement chain.
 *
 * @property string $total_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property RequisitionStatus $status
 * @property ?Carbon $submitted_at
 */
#[Fillable(['project_id', 'number', 'status', 'total_amount', 'raised_by_user_id', 'submitted_at'])]
class PurchaseRequisition extends Model
{
    /** @use HasFactory<PurchaseRequisitionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'total_amount' => 'decimal:4',
            'submitted_at' => 'datetime',
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
     * @return HasMany<PurchaseRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }

    /**
     * The canvass opened against this requisition, if one has been.
     *
     * One requisition is canvassed once — the RFQ screen offers only those that
     * have not been, because a second canvass for the same authorisation is two
     * answers to one question.
     *
     * @return HasOne<Rfq, $this>
     */
    public function rfq(): HasOne
    {
        return $this->hasOne(Rfq::class);
    }

    /**
     * The approval steps opened for this requisition.
     *
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
