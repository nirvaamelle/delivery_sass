<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receiving_report_line_id')->constrained()->restrictOnDelete();

            // accepted + rejected must equal what arrived. Enforced in the
            // service, because the sum spans a row here and a row on the
            // receiving report — but the columns are kept separate so the
            // rejection is a fact on the document rather than a difference
            // somebody has to compute.
            $table->decimal('quantity_accepted', 18, 4);
            $table->decimal('quantity_rejected', 18, 4)->default('0.0000');

            $table->text('rejection_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_lines');
    }
};
