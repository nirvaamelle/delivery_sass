<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_reports', function (Blueprint $table) {
            $table->id();

            // PLAN.md §5: "no receiving report without a PO reference". A
            // non-nullable column rather than a service check, so an importer
            // cannot write one either — a delivery against no order is somebody
            // else's, or a purchase nobody approved.
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // The supplier's own reference from their delivery receipt. Kept
            // because it is what the driver's paperwork says, and reconciling a
            // dispute means quoting their number rather than ours.
            $table->string('delivery_receipt_number');

            $table->dateTime('received_at');
            $table->foreignId('received_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Derived at receipt from the lines, stored so the register can be
            // filtered without recomputing every row.
            $table->boolean('has_shortfall')->default(false);

            $table->text('remarks')->nullable();

            $table->timestamps();

            // One receipt per supplier DR number per order. Two receipts
            // quoting one DR is a double-count or a typo, and both need a human.
            $table->unique(['purchase_order_id', 'delivery_receipt_number'], 'receiving_reports_dr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_reports');
    }
};
