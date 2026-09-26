<?php

use App\Domain\Billing\BillingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_milestone_id')->constrained()->restrictOnDelete();

            /*
             * The verified accomplishment this billing rested on. Recorded, not
             * inferred: a later survey must not change what an already-submitted
             * billing claimed, and "what did we bill this against" is the first
             * question asked when a client disputes one.
             */
            $table->foreignId('accomplishment_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('number')->unique();
            $table->enum('status', BillingStatus::values());

            $table->date('period_start');
            $table->date('period_end');

            /*
             * Gross, retention, net — all three stored. The retention RATE lives
             * on the contract and can be renegotiated; the amount withheld on a
             * billing already submitted cannot be allowed to move with it.
             */
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('retention_rate', 5, 2);
            $table->decimal('retention_amount', 18, 4);
            $table->decimal('net_amount', 18, 4);

            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('evaluated_at')->nullable();

            // The reason the client sent it back — what the QS re-measures
            // against, and what stops the same argument twice in one month.
            $table->text('returned_reason')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
