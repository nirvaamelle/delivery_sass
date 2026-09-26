<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_period_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * Slide 8's "cannot be charged to the project later", made
             * enforceable. PHASE-PLAN.md reads it as barred from THAT PERIOD,
             * which is why the period is part of the key: the corrected expense
             * belongs in the next month, and barring it forever would mean the
             * company never books a real cost because somebody forgot a receipt.
             *
             * Keyed on the RECEIPT, not the amount. Two different bills for the
             * same amount in one month is ordinary; keying on the amount would
             * block the second one.
             */
            $table->string('receipt_reference');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->text('reason');

            $table->dateTime('barred_at');
            $table->foreignId('barred_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * A genuine coding error has to be recoverable — but clearing is an
             * act with a name and a note on it, not a side effect of trying
             * again. The same shape as P2-05's billing deduction block.
             */
            $table->dateTime('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('clearance_note')->nullable();

            $table->timestamps();

            $table->index(
                ['project_id', 'period_year', 'period_month', 'receipt_reference'],
                'expense_bars_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_period_bars');
    }
};
