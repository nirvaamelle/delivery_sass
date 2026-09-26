<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The claim for retention at the end of the defects liability period.
     *
     * P2-07 built the ledger and a `release()` that moves the balance. Read on
     * its own that conflates two events weeks apart: the client agreeing the
     * period has run, and the money arriving. Slide 9 turns on the second —
     * "a project stays open in the books until retention is collected" — so the
     * claim is its own document and the ledger moves when it is paid.
     */
    public function up(): void
    {
        Schema::create('retention_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->decimal('amount', 18, 4);

            $table->date('claimed_on');
            $table->foreignId('claimed_by_user_id')->constrained('users')->restrictOnDelete();

            /*
             * The collection. Null while the claim is outstanding, which is the
             * state the project close is refused over — a project closed on a
             * claim rather than a receipt is closed on a promise.
             */
            $table->date('collected_on')->nullable();
            $table->string('collection_reference')->nullable();
            $table->foreignId('collected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            /*
             * The ledger movement this collection wrote, stamped after the fact
             * so a claim can never claim to be on a ledger that refused it.
             */
            $table->foreignId('retention_entry_id')->nullable()->constrained()->restrictOnDelete();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'collected_on']);
        });

        /*
         * A claim for nothing is a note in a file, and a negative one is a
         * payment to the client wearing the wrong document.
         */
        DB::statement('
            ALTER TABLE retention_releases
            ADD CONSTRAINT retention_releases_amount_is_positive
            CHECK (amount > 0)');

        /*
         * Collected, or not collected — never half of it. A date with no
         * reference cannot be reconciled to a bank line, and a date with nobody
         * against it is the anonymous paperwork slide 9 forbids.
         */
        DB::statement('
            ALTER TABLE retention_releases
            ADD CONSTRAINT retention_releases_collection_is_signed
            CHECK (
                (collected_on IS NULL AND collection_reference IS NULL AND collected_by_user_id IS NULL)
                OR
                (collected_on IS NOT NULL AND collection_reference IS NOT NULL AND collected_by_user_id IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_releases');
    }
};
