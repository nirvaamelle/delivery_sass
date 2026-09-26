<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('back_charges', function (Blueprint $table) {
            $table->id();

            /*
             * F6's table, and the link that makes "computed at punchlist
             * clearing" structural rather than procedural. UNIQUE: one defect,
             * one charge. A second would bill the subcontractor twice for the
             * same handrail, and both rows would trace to the same punchlist
             * line.
             */
            $table->foreignId('punchlist_item_id')->unique()->constrained()->restrictOnDelete();

            /*
             * Carried rather than joined through the item. The payable queries
             * are all by subcontract, and a back-charge whose subject can only
             * be reached through two joins is one an index cannot help.
             */
            $table->foreignId('subcontract_id')->constrained()->restrictOnDelete();

            // Slide 10's handoff rule: every document carries the project code.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            // Where the cost of making the defect good lands in the ledger.
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->decimal('amount', 18, 4);
            $table->text('description');

            $table->dateTime('raised_at');
            $table->foreignId('raised_by_user_id')->constrained('users')->restrictOnDelete();

            /*
             * The posting, stamped in the same transaction that writes it. A
             * back-charge has no life before the ledger takes it — but the
             * column is nullable because the ledger is written by LedgerPoster
             * and the row must exist to be its source document.
             */
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('project_cost_ledger_entry_id')->nullable()
                ->constrained('project_cost_ledger')->restrictOnDelete();

            $table->timestamps();

            $table->index(['subcontract_id', 'raised_at']);
            $table->index(['project_id', 'raised_at']);
        });

        /*
         * A negative back-charge is a payment to the subcontractor wearing the
         * wrong document, and a zero one is a note in a file. Guarded past the
         * service for the same reason every other money column in this build is:
         * an importer or a queued job never calls a service.
         */
        DB::statement('
            ALTER TABLE back_charges
            ADD CONSTRAINT back_charges_amount_is_positive
            CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('back_charges');
    }
};
