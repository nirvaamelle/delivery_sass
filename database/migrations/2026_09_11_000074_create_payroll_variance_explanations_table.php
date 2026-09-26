<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_variance_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * The figures the explanation was written against, snapshotted. An
             * explanation has to stay readable beside the numbers it explained,
             * even if a later correction changes what either run computes today.
             *
             * Plain DECIMAL rather than encrypted, and that is a considered
             * exception to the line-level encryption: these are per-PROJECT
             * labour totals, the same figures P3-11 posts to the ledger in the
             * clear. On a project with one worker a project total does reveal
             * that worker's pay — which is equally true of the ledger, and is a
             * reason for access control on both rather than for encrypting one.
             */
            $table->decimal('previous_amount', 18, 4);
            $table->decimal('current_amount', 18, 4);
            $table->decimal('variance', 18, 4);

            // F10's "explained": a written reason, not an acknowledgement.
            $table->text('explanation');

            $table->dateTime('explained_at');
            $table->foreignId('explained_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One explanation per project per run. A second is a different story
            // told about the same numbers, and the register would carry both.
            $table->unique(['payroll_run_id', 'project_id'], 'payroll_variance_run_project_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_variance_explanations');
    }
};
