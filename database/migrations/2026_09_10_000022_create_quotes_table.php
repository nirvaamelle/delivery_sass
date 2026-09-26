<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            // Derived from the lines, never typed. This is the number the award
            // is decided on, so a hand-entered total is a hand-entered outcome.
            $table->decimal('total_amount', 18, 4)->default('0.0000');

            $table->date('quoted_at');
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            // One quote per vendor per RFQ. A revised price replaces a quote, it
            // does not sit beside it — two rows would let one bidder occupy two
            // places in a three-way comparison.
            $table->unique(['rfq_id', 'vendor_id'], 'quotes_vendor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
