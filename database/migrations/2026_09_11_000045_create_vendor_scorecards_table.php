<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            /*
             * Rated per PO — the deck's first cadence. Unique because the
             * quarterly rules are COUNTS: two cards for one order is one
             * supplier's quarter counted twice, and a suspension threshold that
             * can be reached by rating twice is not a threshold.
             */
            $table->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();

            // The quarter this card falls in, snapshotted so a review reads the
            // same set of cards however the order's dates are later corrected.
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter');

            /*
             * F12's four dimensions. Slide 4 named three; slide 5 named four,
             * adding document completeness, and PHASE-PLAN.md takes slide 5 as
             * authoritative. Percentages, 0.00 to 100.00 — not money, so not
             * DECIMAL(18,4).
             */
            $table->decimal('price_score', 5, 2);
            $table->decimal('delivery_score', 5, 2);
            $table->decimal('quality_score', 5, 2);
            $table->decimal('documents_score', 5, 2);
            $table->decimal('overall_score', 5, 2);

            // The figures the scores were derived from, kept so a card can be
            // explained rather than merely recomputed with today's chain.
            $table->unsignedSmallInteger('days_late')->default(0);
            $table->decimal('rejection_rate', 5, 2)->default('0.00');

            $table->dateTime('rated_at');
            $table->foreignId('rated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['vendor_id', 'period_year', 'period_quarter'], 'vendor_scorecards_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_scorecards');
    }
};
