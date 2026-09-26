<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnover_packs', function (Blueprint $table) {
            $table->id();

            /*
             * UNIQUE. Two packs on one project is two acceptance dates, and
             * everything downstream — final billing, the defects liability
             * clock, retention release — dates from one of them.
             */
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();

            // Slide 9's order as a foreign key: turnover follows substantial
            // completion, and the pack is assembled against the certificate
            // rather than against a project somebody decided looked finished.
            $table->foreignId('substantial_completion_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->dateTime('assembled_at');
            $table->foreignId('assembled_by_user_id')->constrained('users')->restrictOnDelete();

            /*
             * Acceptance. Three columns rather than a status flag, for the
             * reason slide 9 gives: the close-out report has to name who
             * accepted, on whose behalf, and when. The client representative is
             * a name and not a user id — the person signing for the client does
             * not hold an account in our system.
             */
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('client_representative')->nullable();
            $table->text('acceptance_remarks')->nullable();

            $table->timestamps();
        });

        /*
         * Accepted, or not accepted — never half of it. A row carrying a date
         * with nobody's name against it is the anonymous paperwork slide 9
         * forbids, and it is what an importer leaves behind.
         */
        DB::statement('
            ALTER TABLE turnover_packs
            ADD CONSTRAINT turnover_packs_acceptance_is_signed
            CHECK (
                (accepted_at IS NULL AND accepted_by_user_id IS NULL AND client_representative IS NULL)
                OR
                (accepted_at IS NOT NULL AND accepted_by_user_id IS NOT NULL AND client_representative IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('turnover_packs');
    }
};
