<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slide 9 step 4: "applies deductions and back-charges before the invoice."
     *
     * A column on the billing rather than a figure assembled at read time. The
     * invoice is computed from it, and a number the invoice depends on has to
     * be the same number tomorrow — a sum recomputed from live back-charges
     * would restate an invoice already sent.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('deductions_amount', 18, 4)->default(0)->after('retention_amount');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('deductions_amount');
        });
    }
};
