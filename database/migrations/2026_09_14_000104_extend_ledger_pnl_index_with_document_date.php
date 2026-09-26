<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * P6-03 found this one, which is what the task is for.
     *
     * `project_cost_ledger_pnl_index` was (project_id, category). Every P&L,
     * every consolidation row and the whole final account filter on those two
     * AND range on `document_date` — and MySQL can only use a composite index
     * up to the first column not in it, so the date range was resolved by
     * reading every row the first two columns matched. On five years of one
     * project's material postings that is the whole category.
     *
     * `document_date` goes last, after both equality columns: an index is
     * usable up to and including the first range predicate, so a range column
     * placed before an equality one throws the equality away.
     *
     * The lifetime P&L added in P5-09 does not range on the date at all, and it
     * is served by the same index — the first two columns are still a prefix.
     */
    public function up(): void
    {
        /*
         * Added before the old one is dropped, not after. `project_id` is a
         * foreign key, and InnoDB refuses to drop the only index backing one —
         * the error names the constraint rather than the index, which is how
         * this reads as a mystery for ten minutes. The new index leads with the
         * same column, so it takes over the job before the old one goes.
         */
        DB::statement('
            ALTER TABLE project_cost_ledger
            ADD INDEX project_cost_ledger_pnl_range_index (project_id, category, document_date)');

        DB::statement('ALTER TABLE project_cost_ledger DROP INDEX project_cost_ledger_pnl_index');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE project_cost_ledger
            ADD INDEX project_cost_ledger_pnl_index (project_id, category)');

        DB::statement('ALTER TABLE project_cost_ledger DROP INDEX project_cost_ledger_pnl_range_index');
    }
};
