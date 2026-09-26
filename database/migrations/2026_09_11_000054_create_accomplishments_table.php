<?php

use App\Domain\Billing\AccomplishmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accomplishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->date('period_start');
            $table->date('period_end');

            /*
             * Progress to date, and the previous figure alongside it. A progress
             * billing bills the DIFFERENCE — without the previous number on the
             * row, every billing would invoice the whole of the work done so far.
             *
             * Percentages, so 5,2 rather than the money scale.
             */
            $table->decimal('percentage_complete', 5, 2);
            $table->decimal('previous_percentage', 5, 2)->default('0.00');

            $table->enum('status', AccomplishmentStatus::values());

            $table->foreignId('measured_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Where a re-measured DECREASE explains itself. A figure that drops
            // silently is the one nobody can account for at close-out.
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'period_end']);
        });

        DB::statement('ALTER TABLE accomplishments
             ADD CONSTRAINT accomplishments_period_ordered
             CHECK (period_end >= period_start)');

        DB::statement('ALTER TABLE accomplishments
             ADD CONSTRAINT accomplishments_percentage_within_bounds
             CHECK (percentage_complete >= 0 AND percentage_complete <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accomplishments');
    }
};
