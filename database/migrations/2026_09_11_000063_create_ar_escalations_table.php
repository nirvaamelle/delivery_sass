<?php

use App\Domain\Billing\AgingBucket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * F13's other half: a NAMED recipient. "Escalated" with nobody to
             * escalate to is a status change. Stored as a role rather than a
             * user because people change posts and the escalation is addressed
             * to whoever holds the job.
             */
            $table->string('addressed_to_role');

            $table->unsignedSmallInteger('days_outstanding');
            $table->decimal('amount_outstanding', 18, 4);

            /*
             * The bucket this escalation was raised in. It is what stops the
             * weekly sweep raising the same escalation every week — and what
             * allows a genuinely new one when the invoice crosses into sixty
             * days, which is a different conversation.
             */
            $table->enum('bucket', AgingBucket::values());

            $table->dateTime('escalated_at');

            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();

            $table->timestamps();

            // One escalation per invoice per bucket.
            $table->unique(['sales_invoice_id', 'bucket'], 'ar_escalations_invoice_bucket_unique');
            $table->index(['project_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_escalations');
    }
};
