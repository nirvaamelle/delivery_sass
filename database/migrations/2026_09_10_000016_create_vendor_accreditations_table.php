<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_accreditations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            // A history, not a single current row. Renewals append, so "was
            // this vendor accredited when that PO was raised" stays answerable
            // years later — which is the question an auditor actually asks.
            $table->date('accredited_at');
            $table->date('expires_at');

            $table->string('certificate_reference')->nullable();
            $table->foreignId('accredited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['vendor_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_accreditations');
    }
};
