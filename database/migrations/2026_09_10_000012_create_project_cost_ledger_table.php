<?php

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_cost_ledger', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_code_id')->constrained()->restrictOnDelete();

            // Snapshots, not conveniences. PLAN.md §1: every document carries
            // the project code and the cost code. Storing them as values means
            // editing a cost code cannot silently restate last year's
            // postings — a ledger that does that is not an audit trail.
            $table->string('project_code');
            $table->string('cost_code');

            // The document that caused the posting. Without these two columns a
            // P&L line cannot be traced back to the receiving report or payslip
            // behind it, which is the whole point of the handoff spine.
            $table->string('source_document_type', 191);
            $table->unsignedBigInteger('source_document_id');
            $table->string('document_number');

            $table->enum('category', LedgerCategory::values());

            // Signed: a reversing entry is the negative of what it reverses.
            $table->decimal('amount', 18, 4);

            // Which calendar governed this posting, and the document date that
            // selected the period — F3 makes the cutoff type an argument rather
            // than assuming one global calendar.
            $table->enum('cutoff_type', CutoffType::values());
            $table->date('document_date');
            $table->dateTime('posted_at');

            $table->text('description')->nullable();

            // A correction is a new row pointing at the one it cancels, so both
            // stay visible. Unique, because two reversals of one posting would
            // turn a correction into a credit.
            $table->foreignId('reverses_entry_id')->nullable()
                ->constrained('project_cost_ledger')->restrictOnDelete();
            $table->unique('reverses_entry_id', 'project_cost_ledger_reversal_unique');

            $table->timestamps();

            $table->index(['project_id', 'category'], 'project_cost_ledger_pnl_index');
            $table->index(['source_document_type', 'source_document_id'], 'project_cost_ledger_source_index');
            $table->index(['cutoff_type', 'document_date'], 'project_cost_ledger_period_index');
        });

        /*
         * Append-only, enforced by the database.
         *
         * PLAN.md §4 calls this table immutable and §3 makes the Posting
         * service its only writer. A rule that only Eloquent honours is not
         * that rule: an importer, a console command or a stray query builder
         * call goes straight past the model. These triggers do not.
         *
         * The correction path is a reversing entry, which is an INSERT.
         */
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER project_cost_ledger_no_update
            BEFORE UPDATE ON project_cost_ledger
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'project_cost_ledger is append-only. Post a reversing entry instead of editing a posting.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER project_cost_ledger_no_delete
            BEFORE DELETE ON project_cost_ledger
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'project_cost_ledger is append-only. A posting cannot be deleted; reverse it.';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS project_cost_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS project_cost_ledger_no_delete');

        Schema::dropIfExists('project_cost_ledger');
    }
};
