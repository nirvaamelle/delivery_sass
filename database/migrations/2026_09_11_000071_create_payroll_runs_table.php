<?php

use App\Domain\Hris\PayrollRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', PayrollRunStatus::values());

            $table->date('period_start');
            $table->date('period_end');

            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->foreignId('computed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();

            /*
             * Organization-wide totals, in plain DECIMAL. Unlike a line, a total
             * across everybody reveals nobody's rate, and it is the figure the
             * register review and the ledger both reconcile to.
             */
            $table->decimal('gross_total', 18, 4)->default('0.0000');
            $table->decimal('net_total', 18, 4)->default('0.0000');

            /*
             * Employees the run could not price — a certified day with no rate
             * behind it, deductions larger than pay. Reported on the run rather
             * than paid at zero, because a zero line looks like a run that worked.
             */
            $table->json('exceptions')->nullable();

            $table->timestamps();

            // One run per cutoff per organization. A second run for the same
            // period is every day in it payable twice.
            $table->unique(['organization_id', 'period_start'], 'payroll_runs_period_unique');
        });

        DB::statement('ALTER TABLE payroll_runs
             ADD CONSTRAINT payroll_runs_period_ordered
             CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
