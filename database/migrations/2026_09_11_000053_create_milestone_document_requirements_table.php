<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_milestone_id')->constrained()->cascadeOnDelete();

            /*
             * F4: what must be attached before submission opens. Written onto
             * the milestone when the schedule is built rather than read from
             * config at check time — the requirement in force when a contract
             * was signed is the one that contract is held to, and editing the
             * config must not silently restate an existing contract's rules.
             */
            $table->string('document_key');
            $table->string('label');
            $table->boolean('required');

            $table->timestamps();

            $table->unique(['billing_milestone_id', 'document_key'], 'milestone_requirements_unique');
        });

        Schema::create('milestone_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_milestone_id')->constrained()->cascadeOnDelete();

            $table->string('document_key');
            $table->string('reference');

            $table->dateTime('submitted_at');
            $table->foreignId('submitted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            // One document per key per milestone. Two would let the count pass
            // while a different requirement is still unmet.
            $table->unique(['billing_milestone_id', 'document_key'], 'milestone_documents_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_documents');
        Schema::dropIfExists('milestone_document_requirements');
    }
};
