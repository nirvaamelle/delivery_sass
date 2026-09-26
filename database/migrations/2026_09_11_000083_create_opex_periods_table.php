<?php

use App\Domain\Opex\OpexStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opex_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            /*
             * Where the period actually is, as a fact rather than a function of
             * today's date. A month whose scheduler did not run on the 26th has
             * not been cut off, and must not behave as though it had.
             */
            $table->enum('stage', OpexStage::values());

            $table->dateTime('opened_at');
            $table->dateTime('cutoff_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->timestamps();

            // One calendar per organization per month.
            $table->unique(['organization_id', 'period_year', 'period_month'], 'opex_periods_unique');
        });

        Schema::create('opex_period_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opex_period_id')->constrained()->cascadeOnDelete();

            /*
             * Every move, with who made it. A calendar nobody signed cannot
             * answer "who closed May", which is the first question asked when a
             * number in May turns out to be wrong.
             */
            $table->enum('from_stage', OpexStage::values());
            $table->enum('to_stage', OpexStage::values());

            $table->dateTime('performed_at');
            $table->foreignId('performed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opex_period_transitions');
        Schema::dropIfExists('opex_periods');
    }
};
