<?php

use App\Domain\Closeout\TurnoverItemSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnover_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turnover_pack_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('document_key');
            $table->string('label');
            $table->boolean('required')->default(true);

            /*
             * Whether a person files this line or a register answers it. Stored
             * on the row rather than looked up from config at read time: the
             * pack is a record of what was asked for at turnover, and a later
             * edit to the template must not change what an accepted pack says
             * it required.
             */
            $table->enum('source', TurnoverItemSource::values());

            /*
             * The filing, and the signature on it. Nullable together — an
             * outstanding line has no reference, no time and no name, and the
             * CHECK below refuses every other combination.
             */
            $table->string('reference')->nullable();
            $table->dateTime('filed_at')->nullable();
            $table->foreignId('filed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();

            // One line per document per pack. Two rows for "as-built drawings"
            // is two answers to one question.
            $table->unique(['turnover_pack_id', 'document_key'], 'turnover_pack_items_key_unique');
        });

        /*
         * Slide 9's obligation at the line level: "a checklist with named
         * clearers per line, not a status flag." A reference with no filer is
         * a document nobody stands behind.
         */
        DB::statement('
            ALTER TABLE turnover_pack_items
            ADD CONSTRAINT turnover_pack_items_filing_is_signed
            CHECK (
                (reference IS NULL AND filed_at IS NULL AND filed_by_user_id IS NULL)
                OR
                (reference IS NOT NULL AND filed_at IS NOT NULL AND filed_by_user_id IS NOT NULL)
            )');

        /*
         * A register-answered line can never be filed by hand, and the database
         * says so too. Otherwise the whole distinction between a filed line and
         * a derived one rests on one service method that an importer, a console
         * command or a future screen never calls.
         */
        DB::statement("
            ALTER TABLE turnover_pack_items
            ADD CONSTRAINT turnover_pack_items_register_lines_are_not_filed
            CHECK (source = 'filed' OR filed_at IS NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('turnover_pack_items');
    }
};
