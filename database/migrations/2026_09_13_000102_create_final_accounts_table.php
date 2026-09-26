<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The final project P&L and forecast to completion, filed at close-out.
     *
     * The one figure in this phase that is STORED rather than recomputed, and
     * the reason is narrow enough to state. The P&L half is reproducible — the
     * ledger is append-only, so life-to-date sums do not move. The FORECAST
     * half is not: it reads open commitments, and those change every time a
     * purchase order is raised or cancelled. A close-out report whose numbers
     * differ next quarter is not a record of what the project made.
     */
    public function up(): void
    {
        Schema::create('final_accounts', function (Blueprint $table) {
            $table->id();

            // One per project. Two final accounts are two answers to what the
            // project made, and the close-out report cites one of them.
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // The P&L, life to date, one column per ledger category so the
            // filed report can be read without re-summing anything.
            $table->decimal('revenue', 18, 4);
            $table->decimal('material_cost', 18, 4);
            $table->decimal('subcontract_cost', 18, 4);
            $table->decimal('labor_cost', 18, 4);
            $table->decimal('overhead_cost', 18, 4);
            $table->decimal('total_cost', 18, 4);
            $table->decimal('gross_profit', 18, 4);
            $table->decimal('margin_percent', 8, 2);

            // Forecast to completion, as at filing.
            $table->decimal('contract_sum', 18, 4);
            $table->decimal('revenue_remaining', 18, 4);
            $table->decimal('committed_cost', 18, 4);
            $table->decimal('forecast_final_cost', 18, 4);
            $table->decimal('forecast_gross_profit', 18, 4);

            $table->dateTime('filed_at');
            $table->foreignId('filed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });

        /*
         * Cost cannot be negative in total. Individual reversals are negative
         * ledger rows and net out, but a project whose whole cost sums below
         * zero has a reversal without its original, and the P&L that reports it
         * would show a profit larger than the revenue.
         */
        DB::statement('
            ALTER TABLE final_accounts
            ADD CONSTRAINT final_accounts_cost_is_not_negative
            CHECK (total_cost >= 0 AND forecast_final_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('final_accounts');
    }
};
