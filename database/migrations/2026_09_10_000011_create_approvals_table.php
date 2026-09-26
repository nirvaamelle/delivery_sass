<?php

use App\Domain\Approvals\ApprovalDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();

            // Polymorphic, because PLAN.md §3 wants ONE inbox and not seven.
            // Every approvable document in every chain lands in this table.
            $table->string('approvable_type', 191);
            $table->unsignedBigInteger('approvable_id');

            $table->string('document_type', 64);
            $table->unsignedTinyInteger('tier');
            $table->unsignedTinyInteger('step');

            // The role that must sign, captured at request time. Kept as a
            // value rather than a reference because the matrix changes: an
            // approval must stay explainable against the authority that was in
            // force when it was given.
            $table->string('approver_role');

            $table->foreignId('approver_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->enum('decision', ApprovalDecision::values())
                ->default(ApprovalDecision::Pending->value);
            $table->dateTime('decided_at')->nullable();
            $table->text('remarks')->nullable();

            // Whether this step exists because of a sole-source escalation.
            $table->boolean('sole_source')->default(false);

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id'], 'approvals_subject_index');
            $table->index(['decision', 'approver_role'], 'approvals_inbox_index');
            $table->unique(['approvable_type', 'approvable_id', 'step'], 'approvals_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
