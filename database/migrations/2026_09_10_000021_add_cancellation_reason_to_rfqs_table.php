<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            // cancel() already took a reason and dropped it on the floor. A
            // withdrawn solicitation that does not say why is the one somebody
            // will ask about, and the vendors invited to it were told nothing.
            $table->text('cancellation_reason')->nullable()->after('sole_source');
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
