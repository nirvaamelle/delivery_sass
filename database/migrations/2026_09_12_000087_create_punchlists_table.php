<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punchlists', function (Blueprint $table) {
            $table->id();

            /*
             * Slide 9 orders step 1 (substantial completion) before step 2 (the
             * punchlist). A non-nullable foreign key is the only form of that
             * ordering an importer or a queued job cannot walk around.
             *
             * UNIQUE as well: one punchlist per certificate. Two lists of
             * defects against one completion is two answers to "what is
             * outstanding", and final billing reads that answer.
             */
            $table->foreignId('substantial_completion_id')->unique()
                ->constrained()->cascadeOnDelete();

            // Slide 10's handoff rule: every document carries the project code.
            // Derivable through the certificate, carried anyway — the same
            // choice every other document in this build makes.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();
            $table->date('issued_on');
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();

            /*
             * Closing the punchlist is a named act with a note, not a flag that
             * flips when the last item happens to be cleared. An EMPTY punchlist
             * has no open items either, and treating that as cleared would let
             * turnover through on a site nobody walked.
             */
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->text('closure_note')->nullable();

            $table->timestamps();
        });

        /*
         * A closure is all three columns or none of them. A closed_at with no
         * name beside it is precisely the status flag slide 9 forbids, and the
         * service refusing to write one is not enough: PLAN.md §5's argument is
         * that a control living only in a service is not a control.
         */
        DB::statement('
            ALTER TABLE punchlists
            ADD CONSTRAINT punchlists_closure_is_signed
            CHECK (
                (closed_at IS NULL AND closed_by_user_id IS NULL AND closure_note IS NULL)
                OR
                (closed_at IS NOT NULL AND closed_by_user_id IS NOT NULL AND closure_note IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('punchlists');
    }
};
