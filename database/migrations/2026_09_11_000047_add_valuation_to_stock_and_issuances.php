<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            /*
             * A stock card that tracks quantity but not value cannot say what
             * the yard is worth, and cannot value an issue at anything better
             * than a guess. Receipts carry the price on the purchase order;
             * issues carry the weighted average at the moment they leave.
             *
             * Nullable because a physical-count adjustment has no price of its
             * own — it is valued at the average like everything else on the
             * card, and pretending it has one would invent a number.
             */
            $table->decimal('unit_cost', 18, 4)->nullable()->after('quantity');
        });

        Schema::table('material_issuances', function (Blueprint $table) {
            /*
             * Where this issue landed in the ledger. Written in the same
             * transaction as the posting, so an issuance never claims to be
             * posted when the ledger refused it.
             */
            $table->dateTime('posted_at')->nullable()->after('issued_by_user_id');
            $table->foreignId('project_cost_ledger_entry_id')->nullable()
                ->after('posted_at')
                ->constrained('project_cost_ledger')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_issuances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_cost_ledger_entry_id');
            $table->dropColumn('posted_at');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
