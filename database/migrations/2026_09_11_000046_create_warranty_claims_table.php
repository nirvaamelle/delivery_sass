<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number')->unique();
            $table->text('description');

            $table->dateTime('raised_at');
            $table->foreignId('raised_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * F11's missing trigger is specifically an UNRESOLVED claim, so the
             * resolution is a column rather than a deletion: the claim stays on
             * the vendor's history after it is settled, and the trigger reads
             * the null.
             */
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
