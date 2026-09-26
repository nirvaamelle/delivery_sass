<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();

            $table->timestamps();

            // One invitation per vendor per RFQ. A duplicate would inflate the
            // count of quotes sought, and that count is what the three-quote
            // minimum is measured against.
            $table->unique(['rfq_id', 'vendor_id'], 'rfq_recipients_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_recipients');
    }
};
