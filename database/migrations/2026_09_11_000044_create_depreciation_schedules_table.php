<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->decimal('amount', 18, 4);

            /*
             * The schedule is computed once and read monthly. `posted_at` is
             * what stops the same period being charged twice: the OPEX chain in
             * Phase 4 stamps it when the entry reaches the ledger, and a period
             * already stamped is not posted again.
             */
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('project_cost_ledger_entry_id')->nullable()
                ->constrained('project_cost_ledger')->nullOnDelete();

            $table->timestamps();

            // One instalment per machine per month. A second row for one period
            // is a double charge that reads as two ordinary entries.
            // Named explicitly: the generated name runs past MySQL's 64-character
            // identifier limit.
            $table->unique(['equipment_id', 'period_year', 'period_month'], 'depreciation_schedules_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedules');
    }
};
