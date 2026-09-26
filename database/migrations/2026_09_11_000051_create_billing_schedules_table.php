<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_schedules', function (Blueprint $table) {
            $table->id();

            // One schedule per contract. Two would be two sets of milestones
            // against one contract sum, and the percentages would total 200.
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();

            /*
             * Which template this was built from. Recorded rather than assumed,
             * because Part D item 2 may add contract types and a schedule that
             * cannot say which rules produced it cannot be checked against them
             * later.
             */
            $table->string('contract_type');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_schedules');
    }
};
