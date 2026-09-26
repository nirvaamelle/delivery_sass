<?php

use App\Domain\Procurement\PurchaseOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            // PLAN.md §5: "No PO without an approved PR AND a tabulated bid."
            // Both non-nullable, so the AND is a property of the schema rather
            // than a rule the service is trusted to remember. Either alone
            // looks like a complete document set until somebody asks for the
            // other one.
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('bid_tabulation_id')->unique()->constrained()->restrictOnDelete();

            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', PurchaseOrderStatus::values())
                ->default(PurchaseOrderStatus::Draft->value);

            $table->decimal('total_amount', 18, 4)->default('0.0000');
            $table->boolean('sole_source')->default(false);

            $table->date('delivery_date')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('terms')->nullable();

            $table->dateTime('submitted_at')->nullable();

            // A state, not an attachment — F2 gates mobilization on it.
            $table->dateTime('countersigned_at')->nullable();
            $table->string('countersigned_by')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
