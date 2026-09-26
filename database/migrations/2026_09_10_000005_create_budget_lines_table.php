<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();

            // NOT nullable, by design. PLAN.md §5's first control is "no PR
            // without a cost code and confirmed budget availability" — an
            // uncoded budget line would leave that check with nothing to
            // resolve against, so the database refuses one.
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            // PLAN.md §4: money is DECIMAL(18,4) everywhere. Never float,
            // never integer cents.
            $table->decimal('amount', 18, 4);

            $table->timestamps();

            // One line per cost code per budget, so budget availability
            // resolves to a single number.
            $table->unique(['budget_id', 'cost_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
