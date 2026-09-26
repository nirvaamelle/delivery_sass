<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benefits: recurring allowances, service incentive leave, and the payroll line
 * columns that carry them.
 *
 * PLACEHOLDER: benefits are not in PHASE-PLAN.md Part D — see DECISIONS-PENDING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('name');

            // Encrypted: an allowance beside a name is part of somebody's pay.
            $table->text('amount');
            $table->boolean('taxable');

            // Dated like a rate, so a paid cutoff is never restated.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('leave_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('leave_type');
            $table->date('leave_date');

            // 1.0 or 0.5.
            $table->decimal('days', 3, 1);
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Set when a payroll run pays it. One payment, like a DTR day; and
            // like a DTR day, a line that is deleted frees it to be paid again
            // rather than blocking the delete.
            $table->foreignId('payroll_line_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            // One record per person per day; a cancelled one keeps its date, so
            // re-filing that day is refused by the service rather than the index.
            $table->index(['employee_id', 'leave_date']);
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            // Encrypted like every other money column on the line. Nullable so
            // lines computed before benefits existed read as zero.
            $table->text('leave_pay')->nullable()->after('night_differential_pay');
            $table->decimal('leave_days', 4, 1)->default(0)->after('leave_pay');
            $table->text('taxable_allowances')->nullable()->after('leave_days');
            $table->text('non_taxable_allowances')->nullable()->after('taxable_allowances');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['leave_pay', 'leave_days', 'taxable_allowances', 'non_taxable_allowances']);
        });

        Schema::dropIfExists('leave_records');
        Schema::dropIfExists('employee_allowances');
    }
};
