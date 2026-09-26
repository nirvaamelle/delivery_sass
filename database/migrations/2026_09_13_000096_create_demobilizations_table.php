<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demobilizations', function (Blueprint $table) {
            $table->id();

            // One per project. Two would be two answers to "has the site been
            // released", and the close-out checklist reads one of them.
            $table->foreignId('project_id')->unique()->constrained()->restrictOnDelete();

            $table->string('number')->unique();

            $table->dateTime('opened_at');
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();

            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });

        /*
         * Slide 9's rule at the document level: a named clearer, never a status
         * flag. A completion date with nobody against it is the flag wearing a
         * timestamp.
         */
        DB::statement('
            ALTER TABLE demobilizations
            ADD CONSTRAINT demobilizations_completion_is_signed
            CHECK (
                (completed_at IS NULL AND completed_by_user_id IS NULL)
                OR
                (completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('demobilizations');
    }
};
