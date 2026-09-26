<?php

use App\Domain\Procurement\SoleSourceReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sole_source_justifications', function (Blueprint $table) {
            $table->id();

            // One per RFQ. A second justification for one solicitation would
            // leave two explanations on file and nothing saying which was
            // approved.
            $table->foreignId('rfq_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->enum('reason', SoleSourceReason::values());

            // Not nullable. PLAN.md §5 says "written justification" and means
            // written — a reason code alone records a dropdown selection.
            $table->text('narrative');

            $table->decimal('amount', 18, 4);

            // The tier that must approve: one ABOVE the tier the amount would
            // normally route to. Stored because the matrix changes, and this
            // document has to stay explainable against the authority that was
            // in force when it was signed.
            $table->unsignedTinyInteger('tier');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sole_source_justifications');
    }
};
