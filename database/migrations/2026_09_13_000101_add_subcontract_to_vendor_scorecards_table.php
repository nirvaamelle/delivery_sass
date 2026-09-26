<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Vendor AND SUBCONTRACTOR scorecards filed" — slide 9's people-and-assets
     * panel, and the half P1-14 could not build.
     *
     * That task rated a supplier per purchase order, which is the right cadence
     * for goods: delivery, rejection rate, documents with the receiving report.
     * A subcontractor delivers works, has no receiving report, and may hold no
     * purchase order at all. Rating them was therefore impossible, and at
     * close-out — the one moment somebody actually assesses them — the register
     * had nowhere to put the card.
     */
    public function up(): void
    {
        Schema::table('vendor_scorecards', function (Blueprint $table) {
            $table->foreignId('subcontract_id')->nullable()->unique()
                ->after('purchase_order_id')->constrained()->restrictOnDelete();
        });

        /*
         * The unique index on purchase_order_id was created NOT NULL, so it has
         * to be relaxed before a subcontract card can exist. MySQL treats every
         * NULL in a unique index as distinct, so "one card per order" survives.
         */
        DB::statement('ALTER TABLE vendor_scorecards MODIFY purchase_order_id BIGINT UNSIGNED NULL');

        /*
         * Exactly one subject, never both and never neither. A card rating
         * nothing scores a supplier on no work at all, and one rating both
         * would count a quarter twice — the precise failure P1-14's unique
         * index exists to prevent.
         */
        DB::statement('
            ALTER TABLE vendor_scorecards
            ADD CONSTRAINT vendor_scorecards_rates_exactly_one_subject
            CHECK (
                (purchase_order_id IS NOT NULL AND subcontract_id IS NULL)
                OR
                (purchase_order_id IS NULL AND subcontract_id IS NOT NULL)
            )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vendor_scorecards DROP CONSTRAINT vendor_scorecards_rates_exactly_one_subject');

        Schema::table('vendor_scorecards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcontract_id');
        });
    }
};
