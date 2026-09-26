<?php

use App\Domain\Hris\EmploymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            /*
             * What a timekeeper writes on a DTR and what the biometrics export
             * keys on. Two people answering to one number makes every hour
             * either of them works unattributable, so it is unique across the
             * register.
             */
            $table->string('employee_number')->unique();

            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');

            $table->string('position')->nullable();
            $table->string('department')->nullable();

            $table->date('date_hired');

            $table->enum('status', EmploymentStatus::values())->default(EmploymentStatus::Active->value);
            $table->date('separated_on')->nullable();
            $table->text('separation_reason')->nullable();
            $table->foreignId('separated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * PLAN.md §3, and this is the table the rule was written for.
             *
             * Encrypted at rest in the FIRST migration that creates them, not
             * added later — adding encryption afterwards leaves the plaintext in
             * every backup, replica and dump taken in the meantime, and no
             * migration can reach those.
             *
             * `text`, not `string`: ciphertext is far longer than the value it
             * hides, and a varchar(255) would truncate a government number into
             * something that decrypts to nothing.
             */
            $table->text('sss_number')->nullable();
            $table->text('philhealth_number')->nullable();
            $table->text('pagibig_number')->nullable();
            $table->text('tin')->nullable();
            $table->text('bank_account_number')->nullable();

            // Personal contact details, same reasoning.
            $table->text('address')->nullable();
            $table->text('contact_number')->nullable();
            $table->text('emergency_contact')->nullable();

            $table->date('date_of_birth')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
