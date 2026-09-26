<?php

use App\Domain\Hris\OvertimeStatus;
use App\Domain\Hris\OvertimeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->date('work_date');
            $table->enum('type', OvertimeType::values());

            /*
             * A CAP, not a timesheet. Payable premium hours are the smaller of
             * this and what the punches show — an authority for four hours on a
             * day somebody left at five pays nothing.
             */
            $table->decimal('hours_authorised', 5, 2);

            $table->text('reason');
            $table->enum('status', OvertimeStatus::values());

            $table->dateTime('requested_at');
            $table->foreignId('requested_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * Slide 7's "in writing": the memo, form or message reference the
             * approval rests on. Required at approval, so an approval cannot be a
             * verbal okay recorded by whoever clicked the button.
             */
            $table->string('written_reference')->nullable();

            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('decision_remarks')->nullable();

            $table->timestamps();

            // One authority per type per person per day. Two would stack into a
            // cap nobody actually decided on.
            $table->unique(['employee_id', 'work_date', 'type'], 'overtime_authorities_day_type_unique');
        });

        DB::statement('ALTER TABLE overtime_authorities
             ADD CONSTRAINT overtime_authorities_hours_within_day
             CHECK (hours_authorised > 0 AND hours_authorised <= 24)');
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_authorities');
    }
};
