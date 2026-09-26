<?php

use App\Domain\Billing\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();

            // One invoice per billing. Two is the client billed twice for one
            // milestone, with both documents looking complete.
            $table->foreignId('billing_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', InvoiceStatus::values())->default(InvoiceStatus::Issued->value);

            $table->date('issued_on');

            $table->decimal('gross_amount', 18, 4);

            /*
             * Retention, copied from the billing. Withheld by the client until
             * the defects liability period ends, so it is deliberately NOT part
             * of what is collectible — invoicing it now would put a receivable
             * nobody intends to pay yet in front of the weekly AR sweep.
             */
            $table->decimal('retention_amount', 18, 4)->default('0.0000');

            /*
             * F9's other half. P0-09's macro was built for supplier payments,
             * where the company withholds from the vendor; here the direction
             * reverses and the CLIENT withholds from us. Same four columns, same
             * shape, so the ledger can total withholding in both directions.
             */
            $table->withholdingColumns();

            // Gross less retention less withholding: what should actually arrive
            // in the bank.
            $table->decimal('collectible_amount', 18, 4);

            $table->foreignId('issued_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
