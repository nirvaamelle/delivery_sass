<?php

use App\Domain\Hris\DtrStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_time_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('timelog_id')->nullable()->constrained()->nullOnDelete();

            $table->date('work_date');

            // Hours, not money — 5,2 rather than the money scale.
            $table->decimal('hours_worked', 5, 2)->default('0.00');

            $table->enum('status', DtrStatus::values());

            $table->dateTime('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            /*
             * The held-day mechanic lives on these two columns.
             *
             * `held_from_period_end` records WHICH cutoff the day was held out
             * of, so a payroll line can reference a prior-period DTR line and
             * the payslip can say "carried from 1-15 May" rather than showing an
             * unexplained extra day.
             *
             * `paid_in_period_end` is what stops the same day being paid twice:
             * once in its own period and again as a carry-over.
             */
            $table->date('held_from_period_end')->nullable();
            $table->date('paid_in_period_end')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'dtr_employee_day_unique');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_time_records');
    }
};
