<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('three_way_matches', function (Blueprint $table) {
            $table->id();

            /*
             * All three documents are named on the row, non-nullable, because
             * PLAN.md §5's control is "no payment without a three-way match" and
             * a match missing one of its three legs is a two-way match wearing
             * the name. The inspection is here rather than inferred from the
             * receipt: the match is against what inspection ACCEPTED, so the
             * verdict it relied on has to be nameable years later.
             */
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();

            // One match per delivery. A second would put the same goods behind
            // two invoices, each with a complete-looking document set.
            $table->foreignId('receiving_report_id')->unique()->constrained()->restrictOnDelete();

            $table->foreignId('inspection_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->string('invoice_reference');

            /*
             * The three amounts, snapshotted rather than recomputed on read. The
             * order can be revised and a later inspection can change nothing
             * here — what this row has to answer is "on what figures was this
             * payment approved", and a recomputation answers a different
             * question with today's data.
             */
            $table->decimal('invoice_amount', 18, 4);
            $table->decimal('ordered_amount', 18, 4);
            $table->decimal('accepted_amount', 18, 4);

            /*
             * A failed match throws and writes nothing, so every stored row is
             * matched. The column exists because the AP voucher's gate reads it:
             * the payment control is then a fact in the data rather than an
             * assumption in the code that raises the voucher.
             */
            $table->boolean('matched');

            $table->dateTime('matched_at');
            $table->foreignId('matched_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The same invoice number matched twice against one order is a
            // duplicate payment with two valid-looking document sets behind it.
            $table->unique(['purchase_order_id', 'invoice_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('three_way_matches');
    }
};
