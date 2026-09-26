<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')
                ->constrained()->cascadeOnDelete();

            // NOT nullable. PLAN.md §5: "No PR without a cost code." The
            // database refuses an uncoded line so an importer cannot write one
            // either.
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('description');
            $table->decimal('amount', 18, 4);

            $table->timestamps();

            $table->index('cost_code_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_lines');
    }
};
