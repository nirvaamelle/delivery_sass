<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_card_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // All three kept. The book balance is what the card said BEFORE the
            // count, and it is a snapshot rather than something recomputed —
            // recomputing it later would answer a different question, since the
            // adjustment the count itself posts has since changed the balance.
            $table->decimal('book_quantity', 18, 4);
            $table->decimal('counted_quantity', 18, 4);
            $table->decimal('variance', 18, 4);

            $table->dateTime('counted_at');
            $table->foreignId('counted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_counts');
    }
};
