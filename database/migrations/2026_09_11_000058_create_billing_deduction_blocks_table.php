<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_deduction_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            /*
             * This table is the whole point of P2-05.
             *
             * A deduction recorded only on the billing it came from is a note on
             * a dead document. The QS reworks the submission from the same
             * spreadsheet it was copied from, the deducted line is still in it,
             * and the client's evaluator is the only thing standing between the
             * company and billing the same item twice.
             *
             * So the block OUTLIVES its billing and lives at project level,
             * where the next submission will meet it.
             */
            $table->string('line_key');
            $table->foreignId('billing_id')->constrained()->restrictOnDelete();
            $table->text('reason');

            $table->dateTime('blocked_at');

            /*
             * Cleared deliberately, by a named person, with a re-measurement
             * note. The work does eventually get done — but releasing the block
             * is an act, not a side effect of trying again.
             */
            $table->dateTime('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('clearance_note')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'line_key', 'cleared_at'], 'billing_blocks_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_deduction_blocks');
    }
};
