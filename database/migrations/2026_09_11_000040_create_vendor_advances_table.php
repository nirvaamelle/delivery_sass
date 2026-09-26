<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_advances', function (Blueprint $table) {
            $table->id();

            /*
             * F8. An advance is a real payment with NO receipt behind it — the
             * goods have not shipped, and a mobilisation advance is paid so that
             * they can be made. There is deliberately no three_way_match_id on
             * this table: forcing an advance through the match would mean faking
             * a delivery, which is a worse control than admitting the payment is
             * of a different kind and naming what it IS tied to — the order.
             */
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->decimal('amount', 18, 4);

            /*
             * How much of the advance has been recovered at a voucher. Kept as a
             * running figure rather than a boolean because a large advance is
             * recovered across several deliveries, and "settled or not" cannot
             * express a half-recovered one.
             */
            $table->decimal('offset_amount', 18, 4)->default('0.0000');
            $table->foreignId('offset_ap_voucher_id')->nullable()
                ->constrained('ap_vouchers')->nullOnDelete();

            // Why money left before anything arrived. An advance nobody can
            // explain is the one an auditor opens first.
            $table->text('purpose');

            $table->dateTime('released_at');
            $table->foreignId('released_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        /*
         * Recovering more than was advanced turns a repayment into a deduction
         * the vendor never owed. The service caps each offset; this refuses the
         * row regardless of which code path wrote it.
         */
        DB::statement('ALTER TABLE vendor_advances
             ADD CONSTRAINT vendor_advances_offset_within_amount
             CHECK (offset_amount <= amount)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_advances');
    }
};
