<?php

use App\Domain\Closeout\CloseOutEvidence;
use App\Domain\Closeout\CloseOutPanel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('close_out_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('close_out_checklist_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->enum('panel', CloseOutPanel::values());
            $table->string('document_key');
            $table->string('label');

            /*
             * What the build checks before accepting a signature. Copied onto
             * the row rather than read from config at report time: the
             * checklist records what was asked at close-out, and upgrading a
             * line from `manual` next quarter must not restate a report that
             * has already been signed.
             */
            $table->enum('evidence', CloseOutEvidence::values());

            /*
             * Slide 9, as schema: "named clearers per line, not a status flag."
             * There is no status column anywhere on this table. `cleared_at` IS
             * the clearance and the name beside it is what the report is made
             * of; a boolean would be a second place for one fact to live, and
             * the one that survives an import.
             */
            $table->dateTime('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['close_out_checklist_id', 'document_key'], 'close_out_checklist_items_key_unique');
        });

        /*
         * Cleared, or not cleared — never half of it. This is the constraint
         * the whole task rests on: a line carrying a time with nobody against
         * it is exactly the status flag slide 9 forbids, wearing a timestamp.
         */
        DB::statement('
            ALTER TABLE close_out_checklist_items
            ADD CONSTRAINT close_out_checklist_items_clearance_is_signed
            CHECK (
                (cleared_at IS NULL AND cleared_by_user_id IS NULL AND note IS NULL)
                OR
                (cleared_at IS NOT NULL AND cleared_by_user_id IS NOT NULL AND note IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('close_out_checklist_items');
    }
};
