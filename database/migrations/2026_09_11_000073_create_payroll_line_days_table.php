<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_line_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained()->cascadeOnDelete();

            /*
             * PHASE-PLAN.md's held-day obligation: "payroll_lines must be able to
             * reference a DTR line from a prior period." This is that reference.
             *
             * UNIQUE, and that is the most important constraint in Phase 3. A DTR
             * day can sit on one payroll line, ever. Double pay is what every
             * payroll defect eventually turns into — a carried day paid again in
             * its own period, a run recomputed without the old one cancelled — so
             * the guard is a key the database holds rather than a check some
             * future code path could forget to make.
             */
            $table->foreignId('daily_time_record_id')->unique()->constrained()->restrictOnDelete();

            // The project the day was worked on: what labour cost posts against,
            // and what F10's per-project variance is computed over.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->date('work_date');
            $table->boolean('carried')->default(false);

            $table->decimal('hours_regular', 5, 2);
            $table->decimal('hours_overtime', 5, 2);
            $table->decimal('hours_night', 5, 2);

            // Encrypted for the same reason as the line: one day's pay beside its
            // hours is the rate.
            $table->text('amount');

            $table->timestamps();

            $table->index(['project_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_line_days');
    }
};
