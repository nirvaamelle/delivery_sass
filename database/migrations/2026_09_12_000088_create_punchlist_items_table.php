<?php

use App\Domain\Closeout\PunchlistResponsibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punchlist_id')->constrained()->cascadeOnDelete();

            /*
             * The item's name in the close-out report — "item 7", not "row
             * 4,112". Sequenced within the punchlist and unique there, so the
             * number a site engineer writes on a photograph still means
             * something a year later.
             */
            $table->unsignedInteger('item_no');

            $table->text('description');
            $table->string('location')->nullable();

            /*
             * Who has to fix it. Slide 9 computes subcontractor back-charges AT
             * punchlist clearing and applies them at final billing, so an item
             * attributed to a subcontractor is the thing F6 charges against.
             * The subcontract is named here, in the migration that creates the
             * table — not added in P5-02, by which time rows would exist that
             * never named anybody.
             */
            $table->enum('responsibility', PunchlistResponsibility::values());
            $table->foreignId('subcontract_id')->nullable()->constrained()->restrictOnDelete();

            $table->date('raised_on');
            $table->date('due_on')->nullable();

            /*
             * THERE IS NO STATUS COLUMN. Slide 9: "close-out is a checklist with
             * named clearers per line, not a status flag." `cleared_at` is the
             * truth and the name beside it is what the report is made of; a
             * boolean would be a second place for the same fact to live, and the
             * one that survives an import.
             */
            $table->dateTime('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->text('clearance_note')->nullable();

            $table->timestamps();

            $table->unique(['punchlist_id', 'item_no']);
            $table->index(['punchlist_id', 'cleared_at']);
        });

        /*
         * The obligation itself, in the database. A cleared item carries a time,
         * a name and a note, or it is not cleared. Nothing — importer, console
         * command, queued job, a hand on a SQL console — writes an anonymous
         * clearance.
         */
        DB::statement('
            ALTER TABLE punchlist_items
            ADD CONSTRAINT punchlist_items_clearance_is_signed
            CHECK (
                (cleared_at IS NULL AND cleared_by_user_id IS NULL AND clearance_note IS NULL)
                OR
                (cleared_at IS NOT NULL AND cleared_by_user_id IS NOT NULL AND clearance_note IS NOT NULL)
            )');

        /*
         * An item blamed on a subcontractor names which one, and an item on our
         * own forces names none. Without this, F6 computes a back-charge against
         * nobody, or against a subcontractor an item was never attributed to.
         */
        DB::statement("
            ALTER TABLE punchlist_items
            ADD CONSTRAINT punchlist_items_responsibility_is_attributed
            CHECK (
                (responsibility = 'subcontractor' AND subcontract_id IS NOT NULL)
                OR
                (responsibility <> 'subcontractor' AND subcontract_id IS NULL)
            )");

        DB::statement('
            ALTER TABLE punchlist_items
            ADD CONSTRAINT punchlist_items_due_after_raised
            CHECK (due_on IS NULL OR due_on >= raised_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('punchlist_items');
    }
};
