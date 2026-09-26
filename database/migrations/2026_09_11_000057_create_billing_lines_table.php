<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_id')->constrained()->cascadeOnDelete();

            /*
             * The stable identifier for a billable item across submissions. This
             * is what makes "the same item is not billed twice" enforceable:
             * descriptions get retyped and amounts get re-measured, but the key
             * is what says a line on June's billing is the line May's was
             * deducted from.
             */
            $table->string('line_key');
            $table->string('description');
            $table->decimal('amount', 18, 4);

            $table->foreignId('cost_code_id')->nullable()->constrained()->nullOnDelete();

            // Set when the client deducts the line. The reason travels with it —
            // slide 6 asks for the deduction AND its reason logged.
            $table->boolean('deducted')->default(false);
            $table->text('deduction_reason')->nullable();

            $table->timestamps();

            $table->unique(['billing_id', 'line_key'], 'billing_lines_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_lines');
    }
};
