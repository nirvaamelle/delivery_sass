<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            $table->string('description');

            // Quantity carries four places like money: partial units are real
            // in construction (12.5 tonnes, 0.75 cubic metres) and rounding one
            // to a whole number changes the amount payable.
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_total', 18, 4);

            $table->string('unit')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
