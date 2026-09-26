<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timelogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->date('work_date');
            $table->time('time_in');
            $table->time('time_out');
            $table->unsignedSmallInteger('break_minutes')->default(0);

            // Where the row came from. A hand-keyed correction and a biometric
            // punch are different kinds of evidence, and an auditor asks which.
            $table->string('source')->default('biometrics');
            $table->string('batch_reference')->nullable();

            $table->timestamps();

            // One punch per person per day. A double import is a day paid twice,
            // and both rows look entirely ordinary.
            $table->unique(['employee_id', 'work_date'], 'timelogs_employee_day_unique');
            $table->index(['project_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timelogs');
    }
};
