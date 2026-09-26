<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();

            // Every line carries its cost code: PLAN.md §1's rule that every
            // document carries the project code and the cost code. This is what
            // the ledger posting in P1-15 groups by.
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->string('unit')->nullable();

            // How much of this line has actually arrived. Kept on the line
            // rather than derived at read time because the short-delivery check
            // in P1-08 compares against it on every receipt.
            $table->decimal('quantity_received', 18, 4)->default('0.0000');

            $table->timestamps();

            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
