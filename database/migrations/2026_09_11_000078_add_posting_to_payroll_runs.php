<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            /*
             * When this register's labour cost reached the ledger. Written inside
             * the posting transaction, so a register never claims to be posted
             * when the ledger refused it — the same discipline as the material
             * and revenue posters.
             *
             * No ledger entry id here, unlike the material and revenue rows: a
             * payroll run posts ONE row per project, so there is no single entry
             * to point at. The link back is `source_document_type` and
             * `source_document_id` on the ledger rows themselves.
             */
            $table->dateTime('posted_at')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('posted_at');
        });
    }
};
