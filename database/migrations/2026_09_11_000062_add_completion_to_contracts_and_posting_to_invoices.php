<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            /*
             * When the works were accepted. The defects liability period runs
             * from here, so retention cannot be released without it — and a
             * missing date is not permission, in the same way a missing cutoff
             * calendar is not an open period.
             */
            $table->date('completed_on')->nullable()->after('signed_at');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            // Where this invoice's revenue landed. Written inside the posting
            // transaction, so an invoice never claims to be in a ledger that
            // refused it.
            $table->dateTime('posted_at')->nullable()->after('remarks');
            $table->foreignId('project_cost_ledger_entry_id')->nullable()
                ->after('posted_at')
                ->constrained('project_cost_ledger')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_cost_ledger_entry_id');
            $table->dropColumn('posted_at');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('completed_on');
        });
    }
};
