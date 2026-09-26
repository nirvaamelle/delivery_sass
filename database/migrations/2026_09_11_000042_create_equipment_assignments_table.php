<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->date('assigned_on');

            /*
             * Slide 9's demobilization: equipment "returned and logged". Null
             * means the machine is still on that site, and it is the column the
             * one-project-at-a-time rule is enforced against.
             */
            $table->date('released_on')->nullable();

            $table->foreignId('assigned_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('released_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['equipment_id', 'released_on']);
        });

        /*
         * A release before the assignment began describes a machine that left
         * before it arrived. The service refuses it with a readable message;
         * this refuses the row whatever wrote it.
         */
        DB::statement('ALTER TABLE equipment_assignments
             ADD CONSTRAINT equipment_assignments_released_after_assigned
             CHECK (released_on IS NULL OR released_on >= assigned_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_assignments');
    }
};
