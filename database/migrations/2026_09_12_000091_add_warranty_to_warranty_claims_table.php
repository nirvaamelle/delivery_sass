<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F7's second half: "…and to any claim raised against it."
     *
     * `warranty_claims` was built in P1-14, a phase early, because F11's
     * suspension trigger needed something to fire on. What it has never had is
     * the certificate — so a claim could name a vendor but not the promise the
     * vendor was being held to.
     */
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            /*
             * Nullable, and deliberately so. Goods fail whose certificate was
             * never collected, and that claim still has to be raisable — the
             * missing paperwork is itself a scorecard failure, not a reason to
             * refuse the claim and lose the trigger with it.
             */
            $table->foreignId('warranty_id')->nullable()->after('purchase_order_id');

            /*
             * When the goods FAILED, which is not when the claim was typed.
             * Coverage is tested against this: a compressor that died the day
             * before expiry is covered however long the claim took to file, and
             * a register that checked the filing date would deny it.
             */
            $table->date('failed_on')->nullable()->after('description');

            /*
             * Composite, against `warranties (id, vendor_id)`. A single-column
             * reference would let a claim name one vendor and point at another
             * vendor's certificate, and the suspension would land on the wrong
             * company. MySQL skips the check when warranty_id is NULL, which is
             * exactly the un-certificated case above.
             */
            $table->foreign(['warranty_id', 'vendor_id'], 'warranty_claims_warranty_vendor_foreign')
                ->references(['id', 'vendor_id'])
                ->on('warranties')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropForeign('warranty_claims_warranty_vendor_foreign');
            $table->dropColumn(['warranty_id', 'failed_on']);
        });
    }
};
