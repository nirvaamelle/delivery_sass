<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opex_variance_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opex_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            /*
             * The figures the explanation was written against, snapshotted — so
             * it stays readable beside the numbers it explained even if a later
             * correction changes what the period would compute today. The same
             * reasoning as F10's payroll variance explanations.
             */
            $table->decimal('budget_amount', 18, 4);
            $table->decimal('actual_amount', 18, 4);
            $table->decimal('variance_amount', 18, 4);
            $table->decimal('variance_percent', 9, 2);

            // Slide 8's "explained in writing" — a reason, not an acknowledgement.
            $table->text('explanation');

            $table->dateTime('explained_at');
            $table->foreignId('explained_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One explanation per cost code per period. A second is a different
            // story told about the same numbers, and the close would carry both.
            $table->unique(['opex_period_id', 'project_id', 'cost_code_id'], 'opex_variance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opex_variance_explanations');
    }
};
