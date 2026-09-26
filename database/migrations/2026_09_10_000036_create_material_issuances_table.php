<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_card_id')->constrained()->restrictOnDelete();

            // Non-nullable. An issuance is where material becomes project cost,
            // and PLAN.md §1 requires every document to carry its cost code.
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->decimal('quantity', 18, 4);

            $table->string('issued_to')->nullable();
            $table->string('purpose')->nullable();

            $table->dateTime('issued_at');
            $table->foreignId('issued_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issuances');
    }
};
