<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One certified day, priced, on one payroll line — and only one, ever.
 *
 * @property Carbon $work_date
 * @property bool $carried
 * @property string $hours_regular
 * @property string $hours_overtime
 * @property string $hours_night
 * @property string $amount Decrypted by the cast.
 */
#[Fillable([
    'payroll_line_id', 'daily_time_record_id', 'project_id', 'work_date',
    'carried', 'hours_regular', 'hours_overtime', 'hours_night', 'amount',
])]
class PayrollLineDay extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'carried' => 'boolean',
            'hours_regular' => 'decimal:2',
            'hours_overtime' => 'decimal:2',
            'hours_night' => 'decimal:2',
            'amount' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<PayrollLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /**
     * @return BelongsTo<DailyTimeRecord, $this>
     */
    public function dailyTimeRecord(): BelongsTo
    {
        return $this->belongsTo(DailyTimeRecord::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
