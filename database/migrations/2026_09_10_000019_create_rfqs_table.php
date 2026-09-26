<?php

use App\Domain\Procurement\RfqStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();

            // Non-nullable. PR → RFQ → canvass: an RFQ with no requisition
            // behind it is a purchase nobody asked for, and vendors are being
            // invited to quote on it.
            $table->foreignId('purchase_requisition_id')
                ->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->enum('status', RfqStatus::values())
                ->default(RfqStatus::Draft->value);

            $table->date('quotation_deadline');
            $table->dateTime('issued_at')->nullable();

            // A deliberate decision, not an accident of having found only one
            // supplier. The written justification and its escalation are P1-05.
            $table->boolean('sole_source')->default(false);

            $table->timestamps();

            $table->index(['status', 'quotation_deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
