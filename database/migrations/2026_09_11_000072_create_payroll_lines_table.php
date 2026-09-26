<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            /*
             * PLAN.md §3, and extended deliberately past the letter of it. The
             * rule names salary RATES; a net pay figure reveals the rate just as
             * surely, to anybody with the days worked beside it. So every amount
             * on a line is encrypted at rest from this first migration.
             *
             * `text`, holding ciphertext of a decimal string. What is lost is SQL
             * SUM() over these columns — and the per-project totals F10 and the
             * ledger need are summed in bcmath from decrypted values, the same way
             * every other total in this build already is.
             */
            $table->text('basic_pay');
            $table->text('overtime_pay');
            $table->text('night_differential_pay');
            $table->text('gross_pay');
            $table->text('sss_contribution');
            $table->text('philhealth_contribution');
            $table->text('pagibig_contribution');
            $table->text('withholding_tax');
            $table->text('total_deductions');
            $table->text('net_pay');

            $table->unsignedSmallInteger('days_paid');

            // Days held out of an earlier cutoff and paid here — what lets the
            // payslip say "carried from" rather than show unexplained extra days.
            $table->unsignedSmallInteger('carried_days')->default(0);

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_lines_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
