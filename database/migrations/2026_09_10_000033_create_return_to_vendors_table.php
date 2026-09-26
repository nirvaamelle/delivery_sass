<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_to_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // The total rejected across the inspection. Kept as its own figure
            // because this document goes to the vendor, and it has to state
            // plainly what is going back.
            $table->decimal('quantity_returned', 18, 4);

            $table->dateTime('returned_at');
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_to_vendors');
    }
};
