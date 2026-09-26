<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_receipts', function (Blueprint $table) {
            $table->id();

            // Not unique: partial collection is ordinary, and an invoice may be
            // settled across several payments.
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->decimal('amount_received', 18, 4);
            $table->date('received_on');

            $table->string('payment_reference')->nullable();

            /*
             * BIR Form 2307 from the client. Without it, the tax the client
             * withheld is money the company has paid and cannot claim back —
             * and it is invisible, because the invoice reads as settled.
             */
            $table->string('withholding_certificate_reference')->nullable();

            $table->foreignId('received_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['sales_invoice_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_receipts');
    }
};
