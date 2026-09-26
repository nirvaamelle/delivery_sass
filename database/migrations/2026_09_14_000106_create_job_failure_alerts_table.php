<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Failed-job alerting — P6-06.
     *
     * Laravel writes a failure to `failed_jobs` and tells nobody. A payroll run
     * that fails in the queue does not show up as an error on anybody's screen;
     * it shows up as a register that never appeared, noticed on payday. So a
     * failure becomes an alert addressed to a role, and it stays open until a
     * named person acknowledges it — F13's escalation pattern, for the reason
     * F13 needed it.
     */
    public function up(): void
    {
        Schema::create('job_failure_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('job_name');
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->text('exception_message');

            // Who has to act. Payroll failures are Finance's; anything the build
            // has no owner for goes to the administrator rather than to nobody.
            $table->string('addressed_to_role');

            /*
             * Repeats fold into the open alert rather than raising new ones. A
             * worker retrying a broken job every minute would otherwise bury the
             * one alert that matters under sixty identical ones by lunchtime.
             */
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_failed_at');
            $table->dateTime('last_failed_at');

            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->text('resolution')->nullable();

            $table->timestamps();

            $table->index(['job_name', 'acknowledged_at']);
            $table->index(['addressed_to_role', 'acknowledged_at']);
        });

        /*
         * Acknowledged, or not — never half of it. An acknowledgement with
         * nobody against it is an alert that went away without anybody saying
         * what was done about it.
         */
        DB::statement('
            ALTER TABLE job_failure_alerts
            ADD CONSTRAINT job_failure_alerts_acknowledgement_is_signed
            CHECK (
                (acknowledged_at IS NULL AND acknowledged_by_user_id IS NULL AND resolution IS NULL)
                OR
                (acknowledged_at IS NOT NULL AND acknowledged_by_user_id IS NOT NULL AND resolution IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_failure_alerts');
    }
};
