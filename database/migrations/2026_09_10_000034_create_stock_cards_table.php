<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('item_description');
            $table->string('unit')->nullable();

            // Deliberately NO quantity_on_hand column.
            //
            // The balance is derived by summing movements, for the same reason
            // project_cost_ledger is append-only: a stored counter that anything
            // can write is a number nobody can explain, and the question a
            // storekeeper is actually asked is "where did the other forty bags
            // go" — which only the movements answer.

            $table->timestamps();

            $table->unique(['project_id', 'cost_code_id', 'item_description'], 'stock_cards_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cards');
    }
};
