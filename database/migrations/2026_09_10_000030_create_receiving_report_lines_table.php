<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiving_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();

            // All three are stored. `quantity_ordered` is a snapshot of what the
            // PO said at the moment of receipt, so the document still reads
            // correctly if the order is later revised; `quantity_short` is
            // derived at receipt rather than at read time, because the Phase 1
            // exit gate asks for the shortfall NOTED on the document.
            $table->decimal('quantity_ordered', 18, 4);
            $table->decimal('quantity_received', 18, 4);
            $table->decimal('quantity_short', 18, 4)->default('0.0000');

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_report_lines');
    }
};
