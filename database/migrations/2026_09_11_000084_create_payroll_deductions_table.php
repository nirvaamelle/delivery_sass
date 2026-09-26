<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            /*
             * F17's cross-chain write, as a row. PHASE-PLAN.md calls this "the
             * kind of link that quietly never ships" — and the reason it ships
             * here is that the day-26 job writes a real deduction the next
             * payroll run reads, rather than a note somebody is expected to act
             * on.
             *
             * Unique on the advance: one advance produces one deduction, ever.
             * A retried job, a second scheduler or somebody clicking twice would
             * otherwise deduct the same money again — and the employee finds out
             * on payday.
             */
            $table->foreignId('cash_advance_id')->nullable()->unique()
                ->constrained()->restrictOnDelete();

            $table->string('reason');
            $table->decimal('amount', 18, 4);

            // The period the deduction was raised in, so a payslip can say which
            // month's unliquidated advance it is recovering.
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->dateTime('raised_at');

            /*
             * Null until a payroll run takes it. The run stamps both, which is
             * what stops one deduction being applied in two cutoffs.
             */
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('payroll_line_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'applied_at']);
        });

        DB::statement('ALTER TABLE payroll_deductions
             ADD CONSTRAINT payroll_deductions_amount_positive
             CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deductions');
    }
};
