<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demobilization_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demobilization_id')->constrained()->cascadeOnDelete();

            /*
             * A row per person, because clearing somebody for final pay is a
             * named act and not a count. RESTRICT: the signature must outlive
             * any tidying of the 201 file, or the close-out report names
             * nobody.
             */
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            $table->dateTime('cleared_at')->nullable();
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            // One line per person per demobilization.
            $table->unique(['demobilization_id', 'employee_id'], 'demobilization_clearances_person_unique');
        });

        /*
         * The same obligation one level down. There is no status column: the
         * time IS the clearance, and the name beside it is what the close-out
         * report is made of.
         */
        DB::statement('
            ALTER TABLE demobilization_clearances
            ADD CONSTRAINT demobilization_clearances_clearance_is_signed
            CHECK (
                (cleared_at IS NULL AND cleared_by_user_id IS NULL AND note IS NULL)
                OR
                (cleared_at IS NOT NULL AND cleared_by_user_id IS NOT NULL AND note IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('demobilization_clearances');
    }
};
