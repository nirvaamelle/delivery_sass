<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advance_liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->cascadeOnDelete();

            /*
             * Two ways to account for advanced money, and both are liquidations.
             *
             * An EXPENSE liquidation points at a real captured expense, which
             * carries its receipt and cost code through slide 8's own controls —
             * a liquidation with no expense behind it is an advance written off
             * by assertion.
             *
             * A CASH RETURN has no expense, because nothing was bought. Somebody
             * drew ₱10,000, spent ₱8,400 and handed back ₱1,600; forcing that
             * into an expense would mean inventing one for the change in their
             * pocket.
             */
            $table->foreignId('expense_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('cash_return_reference')->nullable();

            $table->decimal('amount', 18, 4);
            $table->date('liquidated_on');

            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['cash_advance_id', 'liquidated_on']);
        });

        DB::statement('ALTER TABLE cash_advance_liquidations
             ADD CONSTRAINT cash_advance_liquidations_amount_positive
             CHECK (amount > 0)');

        /*
         * Exactly one of the two forms. A row with neither accounts for nothing;
         * a row with both would count one peso twice.
         */
        DB::statement('ALTER TABLE cash_advance_liquidations
             ADD CONSTRAINT cash_advance_liquidations_one_form
             CHECK (
                 (expense_id IS NOT NULL AND cash_return_reference IS NULL)
                 OR (expense_id IS NULL AND cash_return_reference IS NOT NULL)
             )');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_liquidations');
    }
};
